<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/Services/KbService.php';

/**
 * Pure parts of the knowledge base service: slug rules from the root
 * .htaccess, slug format, ids and visibility values.
 */
class KbServiceTest extends TestCase {

    private const HTACCESS = <<<'HT'
RewriteEngine On

# /kb/<slug>/ -> /kb/<slug>
RewriteRule ^kb/([a-z0-9-]+)/$ /kb/$1 [R=301,L]

# --- Liste 1 ---
RewriteCond %{THE_REQUEST} ^[A-Z]+\s/kb/faq\.php\?id=75\s
RewriteRule ^kb/faq\.php$ /kb/subunternehmer? [R=301,L]

# --- Liste 2: slug -> Artikel-ID (interne Umschreibung) ---
RewriteRule ^kb/subunternehmer$              kb/faq.php?id=75 [QSA,L]
RewriteRule ^kb/wordpress-bilder-hochladen$  kb/faq.php?id=56 [QSA,L]
	RewriteRule ^kb/avv$ /kb/faq.php?id=77 [QSA,L]
# RewriteRule ^kb/alt$ kb/faq.php?id=12 [QSA,L]
RewriteRule ^kb/doppelt$ kb/faq.php?id=75 [QSA,L]
RewriteRule ^kb/Gross$ kb/faq.php?id=13 [QSA,L]
HT;

    public function testParsesSlugRules(): void {
        $this->assertSame([
            75 => 'subunternehmer',
            56 => 'wordpress-bilder-hochladen',
            77 => 'avv',
        ], KbService::parseSlugRules(self::HTACCESS));
    }

    public function testParsesNothingFromEmptyFile(): void {
        $this->assertSame([], KbService::parseSlugRules(''));
    }

    public function testReadsSlugsFromFile(): void {
        $path = tempnam(sys_get_temp_dir(), 'kb');
        file_put_contents($path, self::HTACCESS);
        try {
            $service = new KbService($path);
            $this->assertSame('avv', $service->slugs()[77]);
        } finally {
            unlink($path);
        }
    }

    public function testMissingFileMeansNoSlugs(): void {
        $service = new KbService(sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '/.htaccess');
        $this->assertSame([], $service->slugs());
    }

    public static function slugs(): array {
        return [
            ['avv', true],
            ['wordpress-bilder-hochladen', true],
            ['a1-b2', true],
            ['', false],
            ['AVV', false],
            ['ueber-uns', true],
            ['über-uns', false],
            ['-avv', false],
            ['avv-', false],
            ['a--b', false],
            ['a/b', false],
            ['a_b', false],
            [str_repeat('a', 101), false],
        ];
    }

    #[DataProvider('slugs')]
    public function testSlugFormat(string $slug, bool $valid): void {
        $this->assertSame($valid, KbService::isValidSlug($slug));
    }

    public static function invalidIds(): array {
        return [[''], ['0'], ['-1'], ['1.5'], ['abc'], ['1 OR 1=1'], ['12345678901']];
    }

    #[DataProvider('invalidIds')]
    public function testRejectsInvalidIds(string $id): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);
        (new KbService())->parseId($id, 'article');
    }

    public function testAcceptsPositiveIds(): void {
        $this->assertSame(77, (new KbService())->parseId(' 77 ', 'article'));
    }

    public function testVisibilityValues(): void {
        $service = new KbService();
        $this->assertSame(0, $service->visibility('internal'));
        $this->assertSame(1, $service->visibility('public'));
        $this->assertSame(2, $service->visibility('featured'));
        $this->assertSame('featured', $service->visibilityName(2));
        $this->assertSame('internal', $service->visibilityName(9));
    }

    public static function invalidVisibility(): array {
        return [['Public'], ['private'], [''], [1], [null], [true]];
    }

    #[DataProvider('invalidVisibility')]
    public function testRejectsUnknownVisibility(mixed $value): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);
        (new KbService())->visibility($value);
    }
}
