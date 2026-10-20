<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Exception\ConfigurationException;
use Hiyori\Models\SourceConfiguration;
use Hiyori\Service\Ingestion\Source\AniListIngestion;
use Hiyori\Service\SourceConfigurationFactory;
use PHPUnit\Framework\TestCase;

/**
 * A source configuration is read from an `include`d PHP file, so a typo in it
 * used to surface as a PHP warning about an undefined array key and then a
 * `TypeError` about a property — neither of which names the file, the source
 * or the key.
 *
 * Every key is therefore checked where the source is read, and a bad one is
 * reported with all three.
 */
final class SourceConfigurationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validConfig(): array
    {
        return [
            'name' => 'AniList',
            'table' => 'anilist',
            'shorthand' => 'al',
            'json_id' => 'id',
            'ingestion_service' => AniListIngestion::class,
        ];
    }

    public function testTheShippedSourcesAllLoad(): void
    {
        $sources = SourceConfigurationFactory::create()->getSources();

        $this->assertArrayHasKey('anilist', $sources);
        $this->assertSame('AniList', $sources['anilist']->getName());
        $this->assertSame('anilist', $sources['anilist']->getTable());
        $this->assertSame('al', $sources['anilist']->getShorthand());
        $this->assertSame('id', $sources['anilist']->getJsonId());
        $this->assertSame(AniListIngestion::class, $sources['anilist']->getIngestionService());
        $this->assertSame(0, $sources['anilist']->getDelay());
        $this->assertFalse($sources['anilist']->isUpdate());
        $this->assertNull($sources['anilist']->getResumePage());
    }

    public function testAMissingRequiredKeyNamesTheFileTheSourceAndTheKey(): void
    {
        $config = $this->validConfig();
        unset($config['shorthand']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sources.php: \'anilist\' is missing the required key "shorthand"');

        new SourceConfiguration($config, 'anilist', 'sources.php');
    }

    public function testAnEmptyRequiredValueIsRejected(): void
    {
        $config = $this->validConfig();
        $config['table'] = '  ';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sources.php: \'anilist\' has an empty value for "table": expected a non-empty string');

        new SourceConfiguration($config, 'anilist', 'sources.php');
    }

    public function testARequiredValueOfTheWrongTypeIsRejected(): void
    {
        $config = $this->validConfig();
        $config['name'] = 42;

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sources.php: \'anilist\' has an invalid value for "name": expected a non-empty string, got int(42)');

        new SourceConfiguration($config, 'anilist', 'sources.php');
    }

    public function testAnIngestionServiceThatDoesNotExistIsRejected(): void
    {
        $config = $this->validConfig();
        $config['ingestion_service'] = 'Hiyori\\Service\\Ingestion\\Source\\NoSuchIngestion';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sources.php: \'anilist\' points at an ingestion service that does not exist');

        new SourceConfiguration($config, 'anilist', 'sources.php');
    }

    public function testAnOptionalKeyOfTheWrongTypeIsRejected(): void
    {
        $config = $this->validConfig();
        $config['delay'] = '5';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sources.php: \'anilist\' has an invalid value for "delay": expected an int, got string("5")');

        new SourceConfiguration($config, 'anilist', 'sources.php');
    }

    public function testTheOptionalKeysAreApplied(): void
    {
        $config = $this->validConfig();
        $config['delay'] = 30;
        $config['update'] = true;
        $config['resume_page'] = 12;

        $source = new SourceConfiguration($config, 'anilist', 'sources.php');

        $this->assertSame(30, $source->getDelay());
        $this->assertTrue($source->isUpdate());
        $this->assertSame(12, $source->getResumePage());
    }

    public function testTheOptionalKeysKeepTheirDefaultsWhenAbsent(): void
    {
        $source = new SourceConfiguration($this->validConfig(), 'anilist', 'sources.php');

        $this->assertSame(0, $source->getDelay());
        $this->assertFalse($source->isUpdate());
        $this->assertNull($source->getResumePage());
    }

    public function testTheSettersStillWork(): void
    {
        $source = new SourceConfiguration($this->validConfig(), 'anilist', 'sources.php');

        $source->setName('AniList (renamed)')
            ->setTable('anilist_v2')
            ->setShorthand('al2')
            ->setJsonId('mal_id')
            ->setDelay(5)
            ->setUpdate(true)
            ->setResumePage(3);

        $this->assertSame('AniList (renamed)', $source->getName());
        $this->assertSame('anilist_v2', $source->getTable());
        $this->assertSame('al2', $source->getShorthand());
        $this->assertSame('mal_id', $source->getJsonId());
        $this->assertSame(5, $source->getDelay());
        $this->assertTrue($source->isUpdate());
        $this->assertSame(3, $source->getResumePage());
    }
}
