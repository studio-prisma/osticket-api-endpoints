<?php
/**
 * Knowledge Base Service
 *
 * Knowledgebase operations for the kb endpoints: list, create and update
 * FAQ categories; search, read, create and update FAQ articles.
 *
 * Changes go through FAQ::update() and Category::update(), so osTicket
 * applies the same cleanup as the staff UI (HTML sanitizing, tag
 * stripping in titles, duplicate checks). Nothing is ever deleted:
 * "removing" an article means setting it to internal.
 *
 * Friendly article URLs (/kb/<slug>) are configured by hand in the
 * .htaccess of the osTicket root. The service only reads that file to
 * report the slug of an article; it never writes it.
 */

declare(strict_types=1);

class KbService
{
    /** TEXT columns (answer, FAQ notes, category description) in bytes */
    public const TEXT_MAX_BYTES = 65535;

    /** faq.question is VARCHAR(255) */
    public const QUESTION_MAX_LENGTH = 255;

    /** faq_category.name is VARCHAR(125), osTicket needs at least 3 */
    public const CATEGORY_NAME_MIN_LENGTH = 3;
    public const CATEGORY_NAME_MAX_LENGTH = 125;

    /** faq_category.notes is TINYTEXT */
    public const CATEGORY_NOTES_MAX_BYTES = 255;

    /** Search page size */
    public const SEARCH_DEFAULT_LIMIT = 50;
    public const SEARCH_MAX_LIMIT = 100;
    public const QUERY_MAX_LENGTH = 200;

    /** Largest .htaccess the slug reader parses */
    public const HTACCESS_MAX_BYTES = 262144;

    /** Deepest category nesting the parent check walks */
    private const MAX_CATEGORY_DEPTH = 20;

    /** API name => osTicket value (faq.ispublished, faq_category.ispublic) */
    public const VISIBILITY = [
        'internal' => 0,
        'public' => 1,
        'featured' => 2,
    ];

    /** @var array<int, string>|null article id => slug, read once per request */
    private ?array $slugs = null;

    /** Path of the .htaccess with the slug rules (tests point it elsewhere) */
    private ?string $htaccessPath;

    public function __construct(?string $htaccessPath = null)
    {
        $this->htaccessPath = $htaccessPath;
    }

    // =========================================================================
    // Categories
    // =========================================================================

    /**
     * All categories with parent, visibility and article count
     */
    public function listCategories(): array
    {
        $categories = [];
        foreach (Category::objects()->order_by('name') as $category) {
            $categories[] = $this->buildCategoryResponse($category);
        }

        usort($categories, fn($a, $b) => strcasecmp($a['fullName'], $b['fullName']));

        return [
            'total' => count($categories),
            'categories' => $categories,
        ];
    }

    /**
     * Create a category (internal unless visibility says otherwise)
     *
     * @throws Exception 400 invalid input
     */
    public function createCategory(array $data): array
    {
        $vars = [
            'id' => 0,
            'name' => $this->categoryName($data['name'] ?? null),
            'description' => $this->requiredHtml($data['description'] ?? null, 'description'),
            'notes' => $this->categoryNotes($data['notes'] ?? ''),
            'ispublic' => $this->visibility($data['visibility'] ?? 'internal'),
            'pid' => $this->parentId($data['parentId'] ?? null, null),
        ];

        $category = Category::create();
        $errors = [];
        if (!$category->update($vars, $errors)) {
            throw new Exception($this->errorMessage($errors, 'Unable to create the category'), 400);
        }

        return $this->buildCategoryResponse($this->lookupCategory((int)$category->getId()));
    }

    /**
     * Change a category; fields left out keep their value
     *
     * @throws Exception 400 invalid input, 404 unknown category
     */
    public function updateCategory(string $id, array $data): array
    {
        $category = $this->lookupCategory($this->parseId($id, 'category'));
        $categoryId = (int)$category->getId();

        $vars = [
            'id' => $categoryId,
            'name' => array_key_exists('name', $data)
                ? $this->categoryName($data['name'])
                : (string)$category->getName(),
            'description' => array_key_exists('description', $data)
                ? $this->requiredHtml($data['description'], 'description')
                : (string)$category->getDescription(),
            'notes' => array_key_exists('notes', $data)
                ? $this->categoryNotes($data['notes'])
                : (string)$category->getNotes(),
            'ispublic' => array_key_exists('visibility', $data)
                ? $this->visibility($data['visibility'])
                : (int)$category->ispublic,
            'pid' => array_key_exists('parentId', $data)
                ? $this->parentId($data['parentId'], $categoryId)
                : ((int)$category->category_pid ?: null),
        ];

        $errors = [];
        if (!$category->update($vars, $errors)) {
            throw new Exception($this->errorMessage($errors, 'Unable to update the category'), 400);
        }

        return $this->buildCategoryResponse($this->lookupCategory($categoryId));
    }

    /**
     * @throws Exception 404
     */
    public function lookupCategory(int $id): Category
    {
        $category = Category::lookup($id);
        if (!$category) {
            throw new Exception('Category not found', 404);
        }
        return $category;
    }

    public function buildCategoryResponse(Category $category): array
    {
        $parentId = (int)$category->category_pid ?: null;
        $parent = $parentId ? Category::lookup($parentId) : null;

        return [
            'id' => (int)$category->getId(),
            'name' => (string)$category->getName(),
            'fullName' => (string)($category->getFullName() ?: $category->getName()),
            'parentId' => $parent ? $parentId : null,
            'parent' => $parent ? (string)$parent->getName() : null,
            'visibility' => $this->visibilityName((int)$category->ispublic),
            'description' => (string)$category->getDescription(),
            'notes' => (string)$category->getNotes(),
            'articleCount' => (int)FAQ::objects()->filter(['category_id' => (int)$category->getId()])->count(),
            'created' => $this->dateValue($category->created ?? null, FAQ_CATEGORY_TABLE, 'category_id', (int)$category->getId(), 'created'),
            'updated' => $this->dateValue($category->updated ?? null, FAQ_CATEGORY_TABLE, 'category_id', (int)$category->getId(), 'updated'),
        ];
    }

    // =========================================================================
    // Articles
    // =========================================================================

    /**
     * Search articles
     *
     * Filters: query (title and answer), categoryId, visibility, slug.
     *
     * @throws Exception 400 invalid filter
     */
    public function searchArticles(array $params): array
    {
        $articles = FAQ::objects();

        $query = trim((string)($params['query'] ?? ''));
        if ($query !== '') {
            if (mb_strlen($query) > self::QUERY_MAX_LENGTH) {
                throw new Exception('query is too long (max ' . self::QUERY_MAX_LENGTH . ' characters)', 400);
            }
            $articles = $articles->filter(Q::any([
                'question__contains' => $query,
                'answer__contains' => $query,
            ]));
        }

        if (isset($params['categoryId']) && $params['categoryId'] !== '') {
            $articles = $articles->filter(['category_id' => $this->parseId((string)$params['categoryId'], 'categoryId')]);
        }

        if (isset($params['visibility']) && $params['visibility'] !== '') {
            $articles = $articles->filter(['ispublished' => $this->visibility($params['visibility'])]);
        }

        if (isset($params['slug']) && $params['slug'] !== '') {
            $slug = (string)$params['slug'];
            if (!self::isValidSlug($slug)) {
                throw new Exception('Invalid slug: use a-z, 0-9 and hyphens', 400);
            }
            $id = array_search($slug, $this->slugs(), true);
            $articles = $articles->filter(['faq_id' => $id === false ? 0 : (int)$id]);
        }

        $limit = $this->intParam($params['limit'] ?? null, self::SEARCH_DEFAULT_LIMIT, 1, self::SEARCH_MAX_LIMIT, 'limit');
        $offset = $this->intParam($params['offset'] ?? null, 0, 0, PHP_INT_MAX, 'offset');

        $total = (int)$articles->count();
        $page = $articles->order_by('-updated')->limit($limit)->offset($offset);

        $results = [];
        foreach ($page as $faq) {
            $results[] = $this->buildArticleResponse($faq, false);
        }

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'articles' => $results,
        ];
    }

    /**
     * One article with answer, notes and help topics
     *
     * @throws Exception 400 malformed id, 404 unknown article
     */
    public function getArticle(string $id): array
    {
        return $this->buildArticleResponse($this->lookupArticle($this->parseId($id, 'article')), true);
    }

    /**
     * Create an article (internal unless visibility says otherwise)
     *
     * @throws Exception 400 invalid input
     */
    public function createArticle(array $data): array
    {
        $categoryId = $this->parseId((string)($data['categoryId'] ?? ''), 'categoryId');
        $this->lookupCategory($categoryId);

        $vars = [
            'id' => 0,
            'question' => $this->question($data['question'] ?? null),
            'answer' => $this->requiredHtml($data['answer'] ?? null, 'answer'),
            'category_id' => $categoryId,
            'ispublished' => $this->visibility($data['visibility'] ?? 'internal'),
            'notes' => $this->optionalHtml($data['notes'] ?? '', 'notes'),
            'topics' => [],
        ];

        $faq = FAQ::create();
        $errors = [];
        if (!$faq->update($vars, $errors)) {
            throw new Exception($this->errorMessage($errors, 'Unable to create the article'), 400);
        }

        return $this->buildArticleResponse($this->lookupArticle((int)$faq->getId()), true);
    }

    /**
     * Change an article; fields left out keep their value
     *
     * FAQ::update() expects the whole article: missing notes or help
     * topics would be cleared. The current values fill the gaps.
     *
     * @throws Exception 400 invalid input, 404 unknown article or category
     */
    public function updateArticle(string $id, array $data): array
    {
        $faq = $this->lookupArticle($this->parseId($id, 'article'));
        $faqId = (int)$faq->getId();

        $categoryId = (int)$faq->getCategoryId();
        if (array_key_exists('categoryId', $data)) {
            $categoryId = $this->parseId((string)$data['categoryId'], 'categoryId');
            $this->lookupCategory($categoryId);
        }

        $vars = [
            'id' => $faqId,
            'question' => array_key_exists('question', $data)
                ? $this->question($data['question'])
                : (string)$faq->getQuestion(),
            'answer' => array_key_exists('answer', $data)
                ? $this->requiredHtml($data['answer'], 'answer')
                : (string)$faq->getAnswer(),
            'category_id' => $categoryId,
            'ispublished' => array_key_exists('visibility', $data)
                ? $this->visibility($data['visibility'])
                : (int)$faq->ispublished,
            'notes' => array_key_exists('notes', $data)
                ? $this->optionalHtml($data['notes'], 'notes')
                : (string)$faq->getNotes(),
            'topics' => $faq->getHelpTopicsIds(),
        ];

        $errors = [];
        if (!$faq->update($vars, $errors)) {
            throw new Exception($this->errorMessage($errors, 'Unable to update the article'), 400);
        }

        return $this->buildArticleResponse($this->lookupArticle($faqId), true);
    }

    /**
     * @throws Exception 404
     */
    public function lookupArticle(int $id): FAQ
    {
        $faq = FAQ::lookup($id);
        if (!$faq) {
            throw new Exception('Article not found', 404);
        }
        return $faq;
    }

    /**
     * @param bool $withContent Include answer, notes and help topics
     */
    public function buildArticleResponse(FAQ $faq, bool $withContent): array
    {
        $id = (int)$faq->getId();
        $category = $faq->getCategory();
        $categoryVisibility = $category ? (int)$category->ispublic : 0;
        $visibility = (int)$faq->ispublished;
        $slug = $this->slugs()[$id] ?? null;
        $base = $this->baseUrl();

        $response = [
            'id' => $id,
            'question' => (string)$faq->getQuestion(),
            'categoryId' => (int)$faq->getCategoryId(),
            'category' => $category ? (string)($category->getFullName() ?: $category->getName()) : null,
            'categoryVisibility' => $this->visibilityName($categoryVisibility),
            'visibility' => $this->visibilityName($visibility),
            // Customers see an article only when it and its category are public
            'visibleInPortal' => $visibility > 0 && $categoryVisibility > 0,
            'slug' => $slug,
            'publicUrl' => $slug !== null && $base !== '' ? $base . '/kb/' . $slug : null,
            'portalUrl' => $base !== '' ? $base . '/kb/faq.php?id=' . $id : null,
            'staffUrl' => $base !== '' ? $base . '/scp/faq.php?id=' . $id : null,
            'created' => $this->dateValue($faq->getCreateDate(), FAQ_TABLE, 'faq_id', $id, 'created'),
            'updated' => $this->dateValue($faq->getUpdateDate(), FAQ_TABLE, 'faq_id', $id, 'updated'),
        ];

        if ($withContent) {
            $response['answer'] = (string)$faq->getAnswer();
            $response['notes'] = (string)$faq->getNotes();
            $topics = [];
            foreach ($faq->getHelpTopics() as $link) {
                $topic = $link->topic ?? null;
                if ($topic) {
                    $topics[] = ['id' => (int)$topic->getId(), 'name' => (string)$topic->getFullName()];
                }
            }
            $response['helpTopics'] = $topics;
        }

        return $response;
    }

    // =========================================================================
    // Slugs (read-only)
    // =========================================================================

    /**
     * Slug format of the friendly URLs: lowercase letters, digits, hyphens
     */
    public static function isValidSlug(string $slug): bool
    {
        return strlen($slug) <= 100 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }

    /**
     * Parse the slug rules of the root .htaccess
     *
     * Rule format: RewriteRule ^kb/<slug>$ kb/faq.php?id=<ID> [QSA,L]
     * Commented-out lines are skipped. The first rule for an id wins.
     *
     * @return array<int, string> article id => slug
     */
    public static function parseSlugRules(string $content): array
    {
        $slugs = [];
        $pattern = '#^[ \t]*RewriteRule[ \t]+\^kb/([a-z0-9-]+)\$[ \t]+/?kb/faq\.php\?id=(\d+)[ \t]#m';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $id = (int)$match[2];
                if ($id > 0 && !isset($slugs[$id]) && self::isValidSlug($match[1])) {
                    $slugs[$id] = $match[1];
                }
            }
        }
        return $slugs;
    }

    /**
     * @return array<int, string> article id => slug ([] if the file is missing)
     */
    public function slugs(): array
    {
        if ($this->slugs !== null) {
            return $this->slugs;
        }

        $this->slugs = [];
        $path = $this->htaccessPath ?? (defined('ROOT_DIR') ? ROOT_DIR . '.htaccess' : null);
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return $this->slugs;
        }

        $size = @filesize($path);
        if ($size === false || $size > self::HTACCESS_MAX_BYTES) {
            error_log('[API Endpoints] Root .htaccess not parsed for KB slugs (unreadable or too large)');
            return $this->slugs;
        }

        $content = @file_get_contents($path);
        if ($content !== false) {
            $this->slugs = self::parseSlugRules($content);
        }
        return $this->slugs;
    }

    // =========================================================================
    // Validation helpers
    // =========================================================================

    /**
     * @throws Exception 400
     */
    public function parseId(string $value, string $field): int
    {
        $value = trim($value);
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $value)) {
            throw new Exception("Invalid {$field}: expected a positive integer", 400);
        }
        return (int)$value;
    }

    /**
     * @throws Exception 400
     */
    public function visibility(mixed $value): int
    {
        if (!is_string($value) || !array_key_exists($value, self::VISIBILITY)) {
            throw new Exception('Invalid visibility: use internal, public or featured', 400);
        }
        return self::VISIBILITY[$value];
    }

    public function visibilityName(int $value): string
    {
        $name = array_search($value, self::VISIBILITY, true);
        return $name === false ? 'internal' : $name;
    }

    /**
     * @throws Exception 400
     */
    private function question(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new Exception('question is required', 400);
        }
        $value = trim($value);
        if (mb_strlen($value) > self::QUESTION_MAX_LENGTH) {
            throw new Exception('question is too long (max ' . self::QUESTION_MAX_LENGTH . ' characters)', 400);
        }
        return $value;
    }

    /**
     * @throws Exception 400
     */
    private function categoryName(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new Exception('name is required', 400);
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < self::CATEGORY_NAME_MIN_LENGTH || $length > self::CATEGORY_NAME_MAX_LENGTH) {
            throw new Exception(sprintf('name must have %d to %d characters',
                self::CATEGORY_NAME_MIN_LENGTH, self::CATEGORY_NAME_MAX_LENGTH), 400);
        }
        return $value;
    }

    /**
     * @throws Exception 400
     */
    private function categoryNotes(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new Exception('notes must be a string', 400);
        }
        if (strlen($value) > self::CATEGORY_NOTES_MAX_BYTES) {
            throw new Exception('notes is too long (max ' . self::CATEGORY_NOTES_MAX_BYTES . ' bytes for categories)', 400);
        }
        return $value;
    }

    /**
     * @throws Exception 400
     */
    private function requiredHtml(mixed $value, string $field): string
    {
        if (!is_string($value) || trim(strip_tags($value)) === '') {
            throw new Exception("{$field} is required", 400);
        }
        return $this->optionalHtml($value, $field);
    }

    /**
     * @throws Exception 400
     */
    private function optionalHtml(mixed $value, string $field): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new Exception("{$field} must be a string", 400);
        }
        if (strlen($value) > self::TEXT_MAX_BYTES) {
            throw new Exception("{$field} is too long (max " . self::TEXT_MAX_BYTES . ' bytes)', 400);
        }
        return $value;
    }

    /**
     * Validate a parent category; null or 0 means top level
     *
     * @param int|null $self Category being changed (must not become its own ancestor)
     * @throws Exception 400 invalid parent, 404 unknown parent
     */
    private function parentId(mixed $value, ?int $self): ?int
    {
        if ($value === null || $value === 0 || $value === '0' || $value === '') {
            return null;
        }
        $parentId = $this->parseId((string)$value, 'parentId');
        if ($self !== null && $parentId === $self) {
            throw new Exception('A category cannot be its own parent', 400);
        }

        $current = $this->lookupCategory($parentId);
        for ($depth = 0; $depth < self::MAX_CATEGORY_DEPTH; $depth++) {
            $next = (int)$current->category_pid;
            if ($next === 0) {
                return $parentId;
            }
            if ($self !== null && $next === $self) {
                throw new Exception('A category cannot be moved below one of its subcategories', 400);
            }
            $current = Category::lookup($next);
            if (!$current) {
                return $parentId;
            }
        }
        throw new Exception('Category nesting is too deep', 400);
    }

    /**
     * @throws Exception 400
     */
    private function intParam(mixed $value, int $default, int $min, int $max, string $field): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value) || (int)$value != $value) {
            throw new Exception("Invalid {$field}", 400);
        }
        $value = (int)$value;
        if ($value < $min || $value > $max) {
            throw new Exception("{$field} must be between {$min} and {$max}", 400);
        }
        return $value;
    }

    /**
     * First osTicket validation error as client message
     */
    private function errorMessage(array $errors, string $fallback): string
    {
        $messages = [];
        foreach ($errors as $field => $message) {
            if (is_string($message) && $message !== '') {
                $messages[] = $field === 'err' ? $message : "{$field}: {$message}";
            }
        }
        return $messages ? implode('; ', $messages) : $fallback;
    }

    private function baseUrl(): string
    {
        global $cfg;
        if (!$cfg || !method_exists($cfg, 'getBaseUrl')) {
            return '';
        }
        return rtrim((string)$cfg->getBaseUrl(), '/');
    }

    /**
     * Stored date
     *
     * After a change in this request the ORM still holds the NOW()
     * expression it wrote, not the stored value, so it is read back.
     * Table and column names come from this class, never from input.
     */
    private function dateValue(mixed $value, string $table, string $idColumn, int $id, string $column): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }
        if (!function_exists('db_query')) {
            return null;
        }
        $result = db_query(sprintf('SELECT `%s` FROM %s WHERE `%s` = %d', $column, $table, $idColumn, $id));
        $row = $result ? db_fetch_array($result) : null;
        return $row ? (string)$row[$column] : null;
    }

    // =========================================================================
    // Singleton
    // =========================================================================

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** For tests */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }
}
