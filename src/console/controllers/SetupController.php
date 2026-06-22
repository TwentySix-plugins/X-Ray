<?php

namespace twentysix\xray\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Checkboxes;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldGroup;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\elements\Category;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Sets up the video game store content structure and seeds sample data.
 * Run with: php craft x-ray/setup/seed
 */
class SetupController extends Controller
{
    private int $siteId;
    private array $fields = [];

    public function actionSeed(): int
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $this->stdout("\n🎮  LevelUp Store — Content Setup\n", Console::FG_CYAN);
        $this->stdout(str_repeat('─', 45) . "\n\n", Console::FG_GREY);

        $this->createFields();
        $this->createCategories();
        $this->createSections();
        $this->seedContent();
        $this->createNestedBlocks();
        $this->seedNestedContent();

        $this->stdout("\n" . str_repeat('─', 45) . "\n", Console::FG_GREY);
        $this->stdout("✅  Setup complete! Clear caches and visit your site.\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Builds (or repairs) only the deeply-nested Matrix structure and re-seeds
     * the demo game with it. Safe to run on an already-seeded install.
     *
     * Run with: php craft x-ray/setup/nested
     */
    public function actionNested(): int
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $this->stdout("\n🧩  Nested Matrix Setup\n", Console::FG_CYAN);
        $this->stdout(str_repeat('─', 45) . "\n", Console::FG_GREY);

        $this->createNestedBlocks();
        $this->seedNestedContent();

        $this->stdout("\n✅  Nested blocks ready. Open a game through X-Ray.\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function createCategories(): void
    {
        $this->stdout("\nCategories\n", Console::FG_YELLOW);

        $categoriesService = Craft::$app->getCategories();
        $group = $categoriesService->getGroupByHandle('gameGenres');

        if (!$group) {
            $group = new CategoryGroup([
                'name' => 'Game Genres',
                'handle' => 'gameGenres',
            ]);

            $group->setSiteSettings([
                $this->siteId => new CategoryGroup_SiteSettings([
                    'siteId' => $this->siteId,
                    'hasUrls' => true,
                    'uriFormat' => 'genres/{slug}',
                    'template' => 'genres/_entry',
                ]),
            ]);

            $layout = new FieldLayout(['type' => Category::class]);
            $tab = new FieldLayoutTab(['name' => 'Content', 'sortOrder' => 1]);
            $tab->setLayout($layout);
            $tab->setElements([new \craft\fieldlayoutelements\TitleField()]);
            $layout->setTabs([$tab]);
            $group->setFieldLayout($layout);

            if ($categoriesService->saveGroup($group)) {
                $this->out('✓ category group', $group->name);
            } else {
                $this->err('category group', $group->name, $group->getFirstErrors());
                return;
            }
        } else {
            $this->out('→ skip', 'Game Genres category group (exists)');
        }

        $genres = ['Action', 'RPG', 'Racing', 'Strategy', 'Simulation', 'Adventure', 'Horror', 'Sports'];
        foreach ($genres as $name) {
            $slug = \craft\helpers\StringHelper::toKebabCase($name);
            $existing = Category::find()->groupId($group->id)->slug($slug)->one();
            if ($existing) {
                $this->out('→ skip', 'Category ' . $name . ' (exists)');
                continue;
            }

            $cat = new Category([
                'groupId' => $group->id,
                'title' => $name,
                'slug' => $slug,
                'siteId' => $this->siteId,
                'enabled' => true,
            ]);

            if (Craft::$app->getElements()->saveElement($cat)) {
                $this->out('✓ category', $name);
            } else {
                $this->err('category', $name, $cat->getErrors());
            }
        }
    }

    // ─── Fields ────────────────────────────────────────────────────────────────

    private function createFields(): void
    {
        $this->stdout("Fields\n", Console::FG_YELLOW);

        $defs = [
            ['class' => PlainText::class, 'handle' => 'headline',       'name' => 'Headline',      'charLimit' => 180],
            ['class' => PlainText::class, 'handle' => 'bodyText',        'name' => 'Body Text',     'multiline' => true, 'initialRows' => 8],
            ['class' => PlainText::class, 'handle' => 'coverGradient',   'name' => 'Cover Gradient'],
            ['class' => Number::class,    'handle' => 'price',           'name' => 'Price',         'decimals' => 2, 'min' => 0],
            ['class' => Number::class,    'handle' => 'rating',          'name' => 'Rating',        'decimals' => 1, 'min' => 0, 'max' => 10],
            ['class' => Date::class,      'handle' => 'releaseDate',     'name' => 'Release Date'],
            ['class' => Lightswitch::class,'handle' => 'featured',       'name' => 'Featured'],
            [
                'class'   => Checkboxes::class,
                'handle'  => 'platform',
                'name'    => 'Platforms',
                'options' => [
                    ['label' => 'PC',              'value' => 'pc',     'default' => false],
                    ['label' => 'PlayStation 5',   'value' => 'ps5',    'default' => false],
                    ['label' => 'Xbox Series X',   'value' => 'xbox',   'default' => false],
                    ['label' => 'Nintendo Switch',  'value' => 'switch', 'default' => false],
                    ['label' => 'Mobile',          'value' => 'mobile', 'default' => false],
                ],
            ],
            [
                'class'   => Dropdown::class,
                'handle'  => 'genre',
                'name'    => 'Genre',
                'options' => [
                    ['label' => '—',           'value' => '',           'default' => true],
                    ['label' => 'Action',      'value' => 'action',     'default' => false],
                    ['label' => 'RPG',         'value' => 'rpg',        'default' => false],
                    ['label' => 'Racing',      'value' => 'racing',     'default' => false],
                    ['label' => 'Strategy',    'value' => 'strategy',   'default' => false],
                    ['label' => 'Simulation',  'value' => 'simulation', 'default' => false],
                    ['label' => 'Adventure',   'value' => 'adventure',  'default' => false],
                    ['label' => 'Horror',      'value' => 'horror',     'default' => false],
                    ['label' => 'Sports',      'value' => 'sports',     'default' => false],
                ],
            ],
        ];

        $service = Craft::$app->getFields();
        foreach ($defs as $def) {
            $existing = $service->getFieldByHandle($def['handle']);
            if ($existing) {
                $this->fields[$def['handle']] = $existing;
                $this->out('→ skip', $def['name'] . ' (exists)');
                continue;
            }
            $class = $def['class'];
            unset($def['class']);
            $field = new $class($def);
            if ($service->saveField($field)) {
                $this->fields[$def['handle']] = $field;
                $this->out('✓ field', $field->name);
            } else {
                $this->err('field', $field->name, $field->getFirstErrors());
            }
        }

        // ── Matrix field: Content Blocks ────────────────────────────────────
        $this->createMatrixField();
    }

    /**
     * Creates the "Content Blocks" Matrix field with three block types:
     *   - Text Block  (heading + body)
     *   - Media Embed (url + caption)
     *   - System Requirements (minSpecs + recSpecs)
     */
    private function createMatrixField(): void
    {
        $existing = Craft::$app->getFields()->getFieldByHandle('contentBlocks');
        if ($existing) {
            $this->fields['contentBlocks'] = $existing;
            $this->out('→ skip', 'Content Blocks matrix (exists)');
            return;
        }

        $entriesService = Craft::$app->entries;

        // ── Block type: Text Block ──────────────────────────────────────────
        $headingField = new PlainText(['handle' => 'blockHeading', 'name' => 'Heading', 'charLimit' => 120]);
        $blockBodyField = new PlainText(['handle' => 'blockBody', 'name' => 'Body', 'multiline' => true, 'initialRows' => 4]);
        Craft::$app->getFields()->saveField($headingField);
        Craft::$app->getFields()->saveField($blockBodyField);

        $textBlockType = new EntryType([
            'name'   => 'Text Block',
            'handle' => 'textBlock',
            'hasTitleField' => false,
        ]);
        $layout1 = new FieldLayout(['type' => Entry::class]);
        $tab1 = new FieldLayoutTab(['name' => 'Content', 'sortOrder' => 1]);
        $tab1->setLayout($layout1);
        $tab1->setElements([new CustomField($headingField), new CustomField($blockBodyField)]);
        $layout1->setTabs([$tab1]);
        $textBlockType->setFieldLayout($layout1);
        if (!$entriesService->saveEntryType($textBlockType)) {
            $this->err('entry type', 'Text Block', $textBlockType->getFirstErrors());
            return;
        }
        $this->out('✓ block type', 'Text Block');

        // ── Block type: Media Embed ─────────────────────────────────────────
        $embedUrlField = new PlainText(['handle' => 'embedUrl', 'name' => 'Embed URL', 'charLimit' => 500]);
        $captionField  = new PlainText(['handle' => 'caption',  'name' => 'Caption',   'charLimit' => 200]);
        Craft::$app->getFields()->saveField($embedUrlField);
        Craft::$app->getFields()->saveField($captionField);

        $mediaBlockType = new EntryType([
            'name'   => 'Media Embed',
            'handle' => 'mediaEmbed',
            'hasTitleField' => false,
        ]);
        $layout2 = new FieldLayout(['type' => Entry::class]);
        $tab2 = new FieldLayoutTab(['name' => 'Content', 'sortOrder' => 1]);
        $tab2->setLayout($layout2);
        $tab2->setElements([new CustomField($embedUrlField), new CustomField($captionField)]);
        $layout2->setTabs([$tab2]);
        $mediaBlockType->setFieldLayout($layout2);
        if (!$entriesService->saveEntryType($mediaBlockType)) {
            $this->err('entry type', 'Media Embed', $mediaBlockType->getFirstErrors());
            return;
        }
        $this->out('✓ block type', 'Media Embed');

        // ── Block type: System Requirements ────────────────────────────────
        $minSpecsField = new PlainText(['handle' => 'minSpecs', 'name' => 'Minimum Specs',     'multiline' => true, 'initialRows' => 3]);
        $recSpecsField = new PlainText(['handle' => 'recSpecs', 'name' => 'Recommended Specs', 'multiline' => true, 'initialRows' => 3]);
        Craft::$app->getFields()->saveField($minSpecsField);
        Craft::$app->getFields()->saveField($recSpecsField);

        $sysReqBlockType = new EntryType([
            'name'   => 'System Requirements',
            'handle' => 'systemRequirements',
            'hasTitleField' => false,
        ]);
        $layout3 = new FieldLayout(['type' => Entry::class]);
        $tab3 = new FieldLayoutTab(['name' => 'Content', 'sortOrder' => 1]);
        $tab3->setLayout($layout3);
        $tab3->setElements([new CustomField($minSpecsField), new CustomField($recSpecsField)]);
        $layout3->setTabs([$tab3]);
        $sysReqBlockType->setFieldLayout($layout3);
        if (!$entriesService->saveEntryType($sysReqBlockType)) {
            $this->err('entry type', 'System Requirements', $sysReqBlockType->getFirstErrors());
            return;
        }
        $this->out('✓ block type', 'System Requirements');

        // ── Create the Matrix field itself ──────────────────────────────────
        $matrixField = new Matrix([
            'name'   => 'Content Blocks',
            'handle' => 'contentBlocks',
        ]);
        $matrixField->setEntryTypes([$textBlockType->id, $mediaBlockType->id, $sysReqBlockType->id]);

        if (Craft::$app->getFields()->saveField($matrixField)) {
            $this->fields['contentBlocks'] = $matrixField;
            $this->out('✓ field', 'Content Blocks (Matrix)');
        } else {
            $this->err('field', 'Content Blocks', $matrixField->getFirstErrors());
        }
    }

    // ─── Sections ──────────────────────────────────────────────────────────────

    private function createSections(): void
    {
        $this->stdout("\nSections\n", Console::FG_YELLOW);

        $configs = [
            [
                'name'        => 'Home',
                'handle'      => 'home',
                'type'        => Section::TYPE_SINGLE,
                'hasUrls'     => true,
                'uri'         => '',
                'template'    => 'index',
                'etName'      => 'Home',
                'etHandle'    => 'home',
                'titleLabel'  => 'Site Name',
                'fields'      => ['headline'],
            ],
            [
                'name'        => 'Games',
                'handle'      => 'games',
                'type'        => Section::TYPE_CHANNEL,
                'hasUrls'     => true,
                'uri'         => 'games/{slug}',
                'template'    => 'games/_entry',
                'etName'      => 'Game',
                'etHandle'    => 'game',
                'titleLabel'  => 'Game Title',
                'fields'      => ['bodyText', 'coverGradient', 'price', 'rating', 'releaseDate', 'featured', 'platform', 'genre', 'contentBlocks'],
            ],
            [
                'name'        => 'News',
                'handle'      => 'news',
                'type'        => Section::TYPE_CHANNEL,
                'hasUrls'     => true,
                'uri'         => 'news/{slug}',
                'template'    => 'news/_entry',
                'etName'      => 'Article',
                'etHandle'    => 'article',
                'titleLabel'  => 'Article Title',
                'fields'      => ['bodyText', 'coverGradient', 'featured'],
            ],
            [
                'name'        => 'About',
                'handle'      => 'about',
                'type'        => Section::TYPE_SINGLE,
                'hasUrls'     => true,
                'uri'         => 'about',
                'template'    => 'about',
                'etName'      => 'About',
                'etHandle'    => 'about',
                'titleLabel'  => 'Page Title',
                'fields'      => ['bodyText'],
            ],
        ];

        $sectionsService = Craft::$app->entries;

        foreach ($configs as $cfg) {
            // Get or create section
            $section = $sectionsService->getSectionByHandle($cfg['handle']);

            $et = null;
            if (!$section) {
                $section = new Section([
                    'name'   => $cfg['name'],
                    'handle' => $cfg['handle'],
                    'type'   => $cfg['type'],
                    'siteSettings' => [
                        $this->siteId => new Section_SiteSettings([
                            'siteId'           => $this->siteId,
                            'enabledByDefault' => true,
                            'hasUrls'          => $cfg['hasUrls'],
                            'uriFormat'        => $cfg['hasUrls'] ? $cfg['uri'] : null,
                            'template'         => $cfg['template'],
                        ]),
                    ],
                ]);

                $et = new EntryType([
                    'name' => $cfg['etName'],
                    'handle' => $cfg['etHandle'],
                    'hasTitleField' => true,
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ]);

                $layout   = new FieldLayout(['type' => Entry::class]);
                $tab      = new FieldLayoutTab(['name' => 'Content', 'sortOrder' => 1]);
                $tab->setLayout($layout);
                $elements = [];
                $elements[] = new \craft\fieldlayoutelements\TitleField();
                foreach ($cfg['fields'] as $handle) {
                    if (isset($this->fields[$handle])) {
                        $elements[] = new CustomField($this->fields[$handle]);
                    }
                }
                $tab->setElements($elements);
                $layout->setTabs([$tab]);
                $et->setFieldLayout($layout);

                if (!$sectionsService->saveEntryType($et)) {
                    $this->err('entry type', $et->name, $et->getFirstErrors());
                    continue;
                }

                $section->setEntryTypes([$et]);

                if ($sectionsService->saveSection($section)) {
                    $this->out('✓ section', $cfg['name']);
                    $this->out('✓ entry type', $et->name);
                } else {
                    $this->err('section', $cfg['name'], $section->getFirstErrors());
                    continue;
                }
            } else {
                $this->out('→ skip', $cfg['name'] . ' section (exists)');

                $entryTypes = $sectionsService->getEntryTypesBySectionId($section->id);
                if (!empty($entryTypes)) {
                    $et = $entryTypes[0];
                    $et->name        = $cfg['etName'];
                    $et->handle      = $cfg['etHandle'];
                    $et->hasTitleField = true;

                    $layout   = new FieldLayout(['type' => Entry::class]);
                    $tab      = new FieldLayoutTab(['name' => 'Content', 'sortOrder' => 1]);
                    $tab->setLayout($layout);
                    $elements = [];
                    $elements[] = new \craft\fieldlayoutelements\TitleField();
                    foreach ($cfg['fields'] as $handle) {
                        if (isset($this->fields[$handle])) {
                            $elements[] = new CustomField($this->fields[$handle]);
                        }
                    }
                    $tab->setElements($elements);
                    $layout->setTabs([$tab]);
                    $et->setFieldLayout($layout);

                    if ($sectionsService->saveEntryType($et)) {
                        $this->out('✓ entry type', $et->name);
                    } else {
                        $this->err('entry type', $et->name, $et->getFirstErrors());
                    }
                }
            }
        }
    }

    // ─── Seed Content ──────────────────────────────────────────────────────────

    private function seedContent(): void
    {
        $this->stdout("\nContent\n", Console::FG_YELLOW);
        $this->seedHome();
        $this->seedAbout();
        $this->seedGames();
        $this->seedNews();
    }

    private function seedHome(): void
    {
        $section = Craft::$app->entries->getSectionByHandle('home');
        if (!$section) return;
        $entry = Entry::find()->sectionId($section->id)->one();
        if (!$entry) return;
        $entry->title = 'LevelUp Store';
        $entry->setFieldValues(['headline' => 'Your Ultimate Gaming Destination']);
        Craft::$app->getElements()->saveElement($entry);
        $this->out('✓ home', 'LevelUp Store');
    }

    private function seedAbout(): void
    {
        $section = Craft::$app->entries->getSectionByHandle('about');
        if (!$section) return;
        $entry = Entry::find()->sectionId($section->id)->one();
        if (!$entry) return;
        $entry->title = 'About LevelUp Store';
        $entry->setFieldValues([
            'bodyText' => "LevelUp Store is the premier destination for gamers worldwide. Founded by passionate players, we bring you the latest titles across all platforms at unbeatable prices.\n\nOur curated selection spans everything from epic open-world adventures to nail-biting competitive shooters. We believe gaming brings people together — which is why we're committed to building the best community-focused game store on the internet.\n\nWhether you're a casual player or a hardcore enthusiast, LevelUp has something for you.",
        ]);
        Craft::$app->getElements()->saveElement($entry);
        $this->out('✓ about', 'About page');
    }

    private function seedGames(): void
    {
        $section = Craft::$app->entries->getSectionByHandle('games');
        if (!$section) return;
        $et = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0] ?? null;
        if (!$et) return;

        // Delete existing entries in this section to ensure fresh title seeding
        $existingEntries = Entry::find()->sectionId($section->id)->all();
        foreach ($existingEntries as $el) {
            Craft::$app->getElements()->deleteElement($el, true);
        }

        $games = [
            [
                'title' => 'Neon Void',
                'slug'  => 'neon-void',
                'bodyText' => "Dive into a breathtaking cyberpunk universe where neon lights pierce the eternal night. As a rogue hacker, you must unravel a vast corporate conspiracy threatening to enslave all of humanity. Neon Void blends lightning-fast first-person combat with deep narrative choices that reshape the world around you.",
                'price' => 59.99, 'rating' => 9.2, 'releaseDate' => '2024-03-15',
                'featured' => true, 'platform' => ['pc', 'ps5', 'xbox'], 'genre' => 'action',
                'coverGradient' => 'linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%)',
                'blocks' => [
                    ['type' => 'textBlock',   'fields' => ['blockHeading' => 'About the Game',      'blockBody' => "Neon Void is a neon-soaked cyberpunk action RPG set in the year 2087. You play as a rogue AI hacker navigating a labyrinthine megacity rife with corruption, bio-enhancements, and corporate warfare."]],
                    ['type' => 'mediaEmbed',  'fields' => ['embedUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'caption' => 'Official Neon Void — Launch Trailer']],
                    ['type' => 'systemRequirements', 'fields' => ['minSpecs' => "OS: Windows 10 64-bit\nCPU: Intel i5-8600 / AMD Ryzen 5 3600\nRAM: 12 GB\nGPU: GTX 1070 / RX 5700\nStorage: 60 GB", 'recSpecs' => "OS: Windows 11 64-bit\nCPU: Intel i7-11700K / AMD Ryzen 7 5800X\nRAM: 16 GB\nGPU: RTX 3080 / RX 6800 XT\nStorage: 60 GB SSD"]],
                ],
            ],
            [
                'title' => 'Dragon Realms VI',
                'slug'  => 'dragon-realms-vi',
                'bodyText' => "The legendary Dragon Realms saga returns in its most ambitious entry yet. Explore a sprawling open world spanning six distinct kingdoms, each with their own culture, quests, and dark secrets. With 200+ hours of content and a revolutionary morality system, every choice ripples through the realm.",
                'price' => 49.99, 'rating' => 9.5, 'releaseDate' => '2024-01-22',
                'featured' => true, 'platform' => ['pc', 'ps5', 'xbox'], 'genre' => 'rpg',
                'coverGradient' => 'linear-gradient(135deg, #134e5e 0%, #71b280 100%)',
                'blocks' => [
                    ['type' => 'textBlock', 'fields' => ['blockHeading' => 'The World of Dragon Realms', 'blockBody' => "Six vast kingdoms await: the frozen tundra of Frostmere, the scorching dunes of Ashvast, the verdant forests of Sylvara, the storm-lashed coasts of Tideshroud, the underground caverns of Deepstone, and the mystical floating isle of Aetheria."]],
                    ['type' => 'systemRequirements', 'fields' => ['minSpecs' => "OS: Windows 10 64-bit\nCPU: Intel i7-8700 / AMD Ryzen 7 3700X\nRAM: 16 GB\nGPU: RTX 2070 / RX 5700 XT\nStorage: 80 GB", 'recSpecs' => "OS: Windows 11 64-bit\nCPU: Intel i9-12900K / AMD Ryzen 9 5900X\nRAM: 32 GB\nGPU: RTX 4080 / RX 7900 XTX\nStorage: 80 GB NVMe SSD"]],
                ],
            ],
            [
                'title' => 'Speed Kings: Turbo',
                'slug'  => 'speed-kings-turbo',
                'bodyText' => "Feel the rush of 400 km/h as you tear across iconic circuits from Tokyo to Monaco. Speed Kings: Turbo features 80 licensed vehicles, real-time weather systems, and a career mode that takes you from amateur circuits to the World Championship.",
                'price' => 39.99, 'rating' => 8.1, 'releaseDate' => '2023-11-10',
                'featured' => false, 'platform' => ['pc', 'ps5', 'xbox', 'switch'], 'genre' => 'racing',
                'coverGradient' => 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)',
                'blocks' => [
                    ['type' => 'textBlock', 'fields' => ['blockHeading' => 'Race the World', 'blockBody' => "80 circuits spanning 24 countries. From the winding mountain passes of the Alps to the underground tunnels of Neo-Tokyo, every track is a masterpiece of environmental storytelling and racing design."]],
                    ['type' => 'mediaEmbed', 'fields' => ['embedUrl' => 'https://youtu.be/turbo_trailer', 'caption' => 'Speed Kings: Turbo — Official Gameplay Trailer']],
                ],
            ],
            [
                'title' => 'Shadow Protocol',
                'slug'  => 'shadow-protocol',
                'bodyText' => "You are Agent Zero — a ghost operative working in the shadows of a collapsing world order. Shadow Protocol redefines stealth-action with adaptive AI, environmental storytelling, and 12 distinct endings shaped entirely by your choices.",
                'price' => 54.99, 'rating' => 8.7, 'releaseDate' => '2024-02-08',
                'featured' => true, 'platform' => ['pc', 'ps5'], 'genre' => 'action',
                'coverGradient' => 'linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%)',
                'blocks' => [
                    ['type' => 'textBlock', 'fields' => ['blockHeading' => 'The Shadow Agency', 'blockBody' => "You work for SPECTER — a clandestine intelligence agency operating outside the law. With 15 gadgets, 4 playstyles, and an adaptive AI that remembers your tactics, no two playthroughs are the same."]],
                    ['type' => 'systemRequirements', 'fields' => ['minSpecs' => "OS: Windows 10 64-bit\nCPU: Intel i5-9600K / AMD Ryzen 5 3600X\nRAM: 12 GB\nGPU: GTX 1080 / RX 5700\nStorage: 45 GB", 'recSpecs' => "OS: Windows 11 64-bit\nCPU: Intel i9-11900K / AMD Ryzen 9 5900X\nRAM: 16 GB\nGPU: RTX 3090 / RX 6900 XT\nStorage: 45 GB SSD"]],
                ],
            ],
            [
                'title' => 'Pixel Farmers',
                'slug'  => 'pixel-farmers',
                'bodyText' => "Escape to the peaceful countryside in this charming farming simulation. Build your dream homestead, cultivate exotic crops, raise animals, and forge friendships with a cast of quirky village characters. The perfect antidote to modern chaos.",
                'price' => 19.99, 'rating' => 8.8, 'releaseDate' => '2023-09-05',
                'featured' => false, 'platform' => ['pc', 'switch', 'mobile'], 'genre' => 'simulation',
                'coverGradient' => 'linear-gradient(135deg, #56ab2f 0%, #a8e063 100%)',
                'blocks' => [
                    ['type' => 'textBlock', 'fields' => ['blockHeading' => 'Your Farm, Your Story', 'blockBody' => "Start with a humble patch of land and build it into a thriving multi-crop estate. Over 200 crops, 40 animal species, and a seasonal event system that keeps every year feeling fresh and alive."]],
                ],
            ],
            [
                'title' => 'Iron Fortress',
                'slug'  => 'iron-fortress',
                'bodyText' => "Command vast armies across a war-torn empire in this deep real-time strategy epic. Manage resources, research technologies, forge alliances and conquer a procedurally-generated world. Every campaign is unique.",
                'price' => 34.99, 'rating' => 8.4, 'releaseDate' => '2023-12-01',
                'featured' => false, 'platform' => ['pc'], 'genre' => 'strategy',
                'coverGradient' => 'linear-gradient(135deg, #c0392b 0%, #8e44ad 100%)',
                'blocks' => [
                    ['type' => 'textBlock', 'fields' => ['blockHeading' => 'Total War, Total Control', 'blockBody' => "Iron Fortress features a 90-hour campaign spanning three eras: the Iron Age, the Steam Age, and the Atomic Age. Each era unlocks new unit types, tactics, and win conditions."]],
                    ['type' => 'systemRequirements', 'fields' => ['minSpecs' => "OS: Windows 10 64-bit\nCPU: Intel i5-10400 / AMD Ryzen 5 3600\nRAM: 8 GB\nGPU: GTX 1060 / RX 580\nStorage: 30 GB", 'recSpecs' => "OS: Windows 10/11 64-bit\nCPU: Intel i7-10700K / AMD Ryzen 7 5700X\nRAM: 16 GB\nGPU: RTX 2080 / RX 6700 XT\nStorage: 30 GB SSD"]],
                ],
            ],
        ];

        foreach ($games as $data) {
            if (Entry::find()->sectionId($section->id)->slug($data['slug'])->exists()) {
                $this->out('→ skip', $data['title'] . ' (exists)');
                continue;
            }
            $entry = new Entry([
                'sectionId' => $section->id,
                'typeId'    => $et->id,
                'siteId'    => $this->siteId,
                'title'     => $data['title'],
                'slug'      => $data['slug'],
                'enabled'   => true,
            ]);

            // Build Matrix blocks for this entry
            $blocks = $data['blocks'] ?? [];
            $fieldValues = [
                'bodyText'      => $data['bodyText'],
                'price'         => $data['price'],
                'rating'        => $data['rating'],
                'releaseDate'   => new \DateTime($data['releaseDate']),
                'featured'      => $data['featured'],
                'platform'      => $data['platform'],
                'genre'         => $data['genre'],
                'coverGradient' => $data['coverGradient'],
            ];
            if (!empty($blocks) && isset($this->fields['contentBlocks'])) {
                $fieldValues['contentBlocks'] = $blocks;
            }
            $entry->setFieldValues($fieldValues);

            if (Craft::$app->getElements()->saveElement($entry)) {
                $this->out('✓ game', $data['title']);
            } else {
                $this->err('game', $data['title'], $entry->getErrors());
            }
        }
    }

    private function seedNews(): void
    {
        $section = Craft::$app->entries->getSectionByHandle('news');
        if (!$section) return;
        $et = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0] ?? null;
        if (!$et) return;

        // Delete existing entries in this section to ensure fresh title seeding
        $existingEntries = Entry::find()->sectionId($section->id)->all();
        foreach ($existingEntries as $el) {
            Craft::$app->getElements()->deleteElement($el, true);
        }

        $articles = [
            [
                'title' => 'Summer Sale: Up to 70% Off Top Titles',
                'slug'  => 'summer-sale-70-off',
                'bodyText' => "Get ready for the biggest sale of the year! Starting this weekend, LevelUp Store is slashing prices on hundreds of titles. You'll find incredible deals across everything from indie gems to blockbuster AAA games.\n\nDon't miss Shadow Protocol for just €16.49, or grab Dragon Realms VI at half price. The sale runs for two weeks only — ending Sunday at midnight. Create a wishlist now to get notified the moment your favourites drop.",
                'featured' => true,
                'coverGradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            ],
            [
                'title' => 'Neon Void Gets a Massive Free Update',
                'slug'  => 'neon-void-major-update',
                'bodyText' => "Neon Void's developers just dropped Update 2.0, adding an entire new district to explore: The Underbelly — a sprawling underground market where nothing is legal and everything is for sale.\n\nThe update also brings a new storyline with 4 hours of content, 15 new side quests, a photo mode, and significant performance improvements across all platforms. Free for all existing owners.",
                'featured' => false,
                'coverGradient' => 'linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%)',
            ],
            [
                'title' => 'Most Anticipated Games of 2025',
                'slug'  => 'most-anticipated-2025',
                'bodyText' => "2025 is shaping up to be one of the best years in gaming history. Our editors have compiled their most-wanted list, from massive sequels to bold new IPs.\n\nHighlights include the long-awaited sequel to Neon Void, a brand-new tactical RPG from an acclaimed indie studio, and several remasters of classics that defined a generation. Buckle up — it's going to be a legendary year.",
                'featured' => true,
                'coverGradient' => 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
            ],
        ];

        foreach ($articles as $data) {
            if (Entry::find()->sectionId($section->id)->slug($data['slug'])->exists()) {
                $this->out('→ skip', $data['title'] . ' (exists)');
                continue;
            }
            $entry = new Entry([
                'sectionId' => $section->id,
                'typeId'    => $et->id,
                'siteId'    => $this->siteId,
                'title'     => $data['title'],
                'slug'      => $data['slug'],
                'enabled'   => true,
            ]);
            $entry->setFieldValues([
                'bodyText'      => $data['bodyText'],
                'featured'      => $data['featured'],
                'coverGradient' => $data['coverGradient'],
            ]);
            if (Craft::$app->getElements()->saveElement($entry)) {
                $this->out('✓ article', $data['title']);
            } else {
                $this->err('article', $data['title'], $entry->getErrors());
            }
        }
    }

    // ─── Nested Matrix (Matrix → Matrix → Matrix) ───────────────────────────────

    /**
     * Builds a three-level-deep Matrix nest and grafts it onto the existing
     * "Content Blocks" field:
     *
     *   contentBlocks (Matrix L1)
     *     └─ Accordion              accordionTitle + accordionPanels (Matrix L2)
     *          └─ Panel             panelLabel + panelContent (Matrix L3)
     *               └─ Text Block / Media Embed   (re-uses existing block types)
     *
     * Everything is created idempotently so it can run on a seeded database.
     */
    private function createNestedBlocks(): void
    {
        $this->stdout("\nNested Blocks\n", Console::FG_YELLOW);

        $fields   = Craft::$app->getFields();
        $entries  = Craft::$app->entries;

        // L3 already exists from the flat seed — re-use those block types.
        $textType  = $entries->getEntryTypeByHandle('textBlock');
        $mediaType = $entries->getEntryTypeByHandle('mediaEmbed');
        if (!$textType || !$mediaType) {
            $this->stdout("  ✗ base block types missing — run x-ray/setup/seed first.\n", Console::FG_RED);
            return;
        }

        // ── L3 field: Panel Content (Matrix of Text Block / Media Embed) ────
        $panelContent = $fields->getFieldByHandle('panelContent');
        if (!$panelContent) {
            $panelContent = new Matrix(['name' => 'Panel Content', 'handle' => 'panelContent']);
            $panelContent->setEntryTypes([$textType->id, $mediaType->id]);
            $fields->saveField($panelContent);
            $this->out('✓ field', 'Panel Content (Matrix L3)');
        } else {
            $this->out('→ skip', 'Panel Content (exists)');
        }

        // ── L2 block type: Panel (label + nested Panel Content matrix) ──────
        $panelType = $entries->getEntryTypeByHandle('panel');
        if (!$panelType) {
            $panelLabel = $this->ensurePlainText('panelLabel', 'Panel Label', ['charLimit' => 120]);
            $panelType  = $this->makeBlockType('Panel', 'panel', [$panelLabel, $panelContent]);
            $this->out('✓ block type', 'Panel (L2)');
        } else {
            $this->out('→ skip', 'Panel block type (exists)');
        }

        // ── L2 field: Accordion Panels (Matrix of Panel) ────────────────────
        $accordionPanels = $fields->getFieldByHandle('accordionPanels');
        if (!$accordionPanels) {
            $accordionPanels = new Matrix(['name' => 'Accordion Panels', 'handle' => 'accordionPanels']);
            $accordionPanels->setEntryTypes([$panelType->id]);
            $fields->saveField($accordionPanels);
            $this->out('✓ field', 'Accordion Panels (Matrix L2)');
        } else {
            $this->out('→ skip', 'Accordion Panels (exists)');
        }

        // ── L1 block type: Accordion (title + nested Accordion Panels) ──────
        $accordionType = $entries->getEntryTypeByHandle('accordion');
        if (!$accordionType) {
            $accordionTitle = $this->ensurePlainText('accordionTitle', 'Accordion Title', ['charLimit' => 160]);
            $accordionType  = $this->makeBlockType('Accordion', 'accordion', [$accordionTitle, $accordionPanels]);
            $this->out('✓ block type', 'Accordion (L1)');
        } else {
            $this->out('→ skip', 'Accordion block type (exists)');
        }

        // ── Graft Accordion onto the top-level Content Blocks matrix ────────
        $contentBlocks = $fields->getFieldByHandle('contentBlocks');
        if ($contentBlocks instanceof Matrix) {
            $types   = $contentBlocks->getEntryTypes();
            $handles = array_map(fn($t) => $t->handle, $types);
            if (!in_array('accordion', $handles, true)) {
                $types[] = $accordionType;
                $contentBlocks->setEntryTypes($types);
                $fields->saveField($contentBlocks);
                $this->out('✓ link', 'Accordion → Content Blocks');
            } else {
                $this->out('→ skip', 'Accordion already linked');
            }
        }

        $this->fields['contentBlocks'] = $fields->getFieldByHandle('contentBlocks');
    }

    /**
     * Adds a nested Accordion block (with panels, each holding their own
     * Text/Media blocks) to the "Neon Void" game so X-Ray has
     * three levels of Matrix to walk.
     */
    private function seedNestedContent(): void
    {
        $this->stdout("\nNested Content\n", Console::FG_YELLOW);

        $section = Craft::$app->entries->getSectionByHandle('games');
        if (!$section) {
            $this->out('→ skip', 'games section missing');
            return;
        }

        $entry = Entry::find()->sectionId($section->id)->slug('dragon-realms-vi')->one();
        if (!$entry) {
            $this->out('→ skip', 'Dragon Realms VI entry not found');
            return;
        }

        $accordion = [
            'new:accordion' => [
                'type'   => 'accordion',
                'fields' => [
                    'accordionTitle' => 'Frequently Asked Questions',
                    'accordionPanels' => [
                        'new:panel1' => [
                            'type'   => 'panel',
                            'fields' => [
                                'panelLabel'   => 'Is there co-op multiplayer?',
                                'panelContent' => [
                                    'new:pc1a' => ['type' => 'textBlock', 'fields' => [
                                        'blockHeading' => 'Two-player netrunning',
                                        'blockBody'    => "Yes — the entire 30-hour campaign supports drop-in/drop-out co-op. One player jacks into the net while the other handles the meatspace combat.",
                                    ]],
                                    'new:pc1b' => ['type' => 'mediaEmbed', 'fields' => [
                                        'embedUrl' => 'https://www.youtube.com/watch?v=coop_demo',
                                        'caption'  => 'Co-op gameplay walkthrough',
                                    ]],
                                ],
                            ],
                        ],
                        'new:panel2' => [
                            'type'   => 'panel',
                            'fields' => [
                                'panelLabel'   => 'Will there be DLC?',
                                'panelContent' => [
                                    'new:pc2a' => ['type' => 'textBlock', 'fields' => [
                                        'blockHeading' => 'The Underbelly expansion',
                                        'blockBody'    => "A free 2.0 update adds a whole new district. Two paid story expansions are planned for the year following launch.",
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $entry->setFieldValues(['contentBlocks' => $accordion]);

        if (Craft::$app->getElements()->saveElement($entry)) {
            $this->out('✓ nested', 'Dragon Realms VI → Accordion (3 levels deep)');
        } else {
            $this->err('nested', 'Dragon Realms VI', $entry->getErrors());
        }
    }

    /** Get-or-create a PlainText field by handle. */
    private function ensurePlainText(string $handle, string $name, array $extra = []): PlainText
    {
        $existing = Craft::$app->getFields()->getFieldByHandle($handle);
        if ($existing instanceof PlainText) {
            return $existing;
        }
        $field = new PlainText(array_merge(['handle' => $handle, 'name' => $name], $extra));
        Craft::$app->getFields()->saveField($field);
        return $field;
    }

    /** Create a titleless Matrix block (entry) type from a list of fields. */
    private function makeBlockType(string $name, string $handle, array $fields): EntryType
    {
        $type = new EntryType(['name' => $name, 'handle' => $handle, 'hasTitleField' => false]);
        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'sortOrder' => 1]);
        $tab->setLayout($layout);
        $tab->setElements(array_map(fn($f) => new CustomField($f), $fields));
        $layout->setTabs([$tab]);
        $type->setFieldLayout($layout);
        Craft::$app->entries->saveEntryType($type);
        return $type;
    }

    // ─── Stress test: deep chain of distinctly-named Matrix blocks ──────────────

    /**
     * Pool of distinct, hierarchy-flavoured names — one per nesting level.
     * (More than enough for a sane stress depth; falls back to "Level N".)
     */
    private const CHAIN_NAMES = [
        'Continent', 'Country', 'Region', 'Province', 'City',
        'District', 'Borough', 'Quarter', 'Street', 'Building',
    ];

    /** Per-level metadata, keyed by level (1-based). Built by createChainBlocks(). */
    private array $chainLevels = [];

    /**
     * Builds an N-level chain where EVERY level is its own distinctly-named
     * block type, each holding a Matrix of the next level down:
     *
     *   Continent → Country → Region → Province → City → … (leaf holds text)
     *
     * Then seeds the whole chain onto "Neon Void" to stress-test the
     * X-Ray.s recursion with a different name at every depth.
     *
     * Run with: php craft x-ray/setup/stress [levels=5]
     */
    public function actionStress(int $levels = 5): int
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $levels = max(1, min($levels, count(self::CHAIN_NAMES)));
        $this->stdout("\n🪆  Deep Nest Stress Test — {$levels} distinctly-named levels\n", Console::FG_CYAN);
        $this->stdout(str_repeat('─', 45) . "\n", Console::FG_GREY);

        $top = $this->createChainBlocks($levels);
        if ($top) {
            $this->seedDeepNest($levels);
        }

        $this->stdout("\n✅  {$levels}-level chain ready on Neon Void.\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Creates one distinctly-named block type per level, wiring each level's
     * Matrix field to the next level down. Built deepest-first so each child
     * type exists before its parent references it. Returns the top-level type.
     */
    private function createChainBlocks(int $levels): ?EntryType
    {
        $this->stdout("\nChain Blocks\n", Console::FG_YELLOW);

        $fields  = Craft::$app->getFields();
        $entries = Craft::$app->entries;

        // Pre-compute the handles for every level so seeding can reference them.
        $this->chainLevels = [];
        for ($i = 1; $i <= $levels; $i++) {
            $name = self::CHAIN_NAMES[$i - 1] ?? "Level{$i}";
            $this->chainLevels[$i] = [
                'name'         => $name,
                'typeHandle'   => 'chain' . $name,
                'labelHandle'  => 'chain' . $name . 'Label',
                'bodyHandle'   => 'chain' . $name . 'Body',
                'matrixHandle' => 'chain' . $name . 'Items',
                'isLeaf'       => ($i === $levels),
            ];
        }

        // Deepest → shallowest, so the child type is ready before the parent.
        $childType = null;
        for ($i = $levels; $i >= 1; $i--) {
            $lvl = $this->chainLevels[$i];

            $existing = $entries->getEntryTypeByHandle($lvl['typeHandle']);
            if ($existing) {
                $childType = $existing;
                $this->out('→ skip', $lvl['name'] . ' (exists)');
                continue;
            }

            $label = $this->ensurePlainText($lvl['labelHandle'], $lvl['name'] . ' Name', ['charLimit' => 160]);
            $blockFields = [$label];

            if ($lvl['isLeaf']) {
                // Leaf carries body text instead of a nested Matrix.
                $blockFields[] = $this->ensurePlainText(
                    $lvl['bodyHandle'], $lvl['name'] . ' Notes',
                    ['multiline' => true, 'initialRows' => 3]
                );
            } else {
                $matrix = new Matrix(['name' => $lvl['name'] . ' Items', 'handle' => $lvl['matrixHandle']]);
                $matrix->setEntryTypes([$childType->id]);
                $fields->saveField($matrix);
                $blockFields[] = $matrix;
            }

            $childType = $this->makeBlockType($lvl['name'], $lvl['typeHandle'], $blockFields);
            $this->out('✓ block type', $lvl['name'] . ($lvl['isLeaf'] ? ' (leaf)' : " → {$this->chainLevels[$i + 1]['name']}"));
        }

        $topType = $entries->getEntryTypeByHandle($this->chainLevels[1]['typeHandle']);

        // ── Graft the top level onto Content Blocks; retire the old "nest" ──
        $contentBlocks = $fields->getFieldByHandle('contentBlocks');
        if ($contentBlocks instanceof Matrix && $topType) {
            $types = array_filter(
                $contentBlocks->getEntryTypes(),
                fn($t) => $t->handle !== 'nest'   // drop the old self-referential block
            );
            $handles = array_map(fn($t) => $t->handle, $types);
            if (!in_array($topType->handle, $handles, true)) {
                $types[] = $topType;
            }
            $contentBlocks->setEntryTypes(array_values($types));
            $fields->saveField($contentBlocks);
            $this->out('✓ link', $this->chainLevels[1]['name'] . ' → Content Blocks');
        }

        return $topType;
    }

    /** Seeds (or replaces) the distinctly-named chain on the Neon Void game. */
    private function seedDeepNest(int $levels): void
    {
        $this->stdout("\nChain Content\n", Console::FG_YELLOW);

        $section = Craft::$app->entries->getSectionByHandle('games');
        $entry = $section
            ? Entry::find()->sectionId($section->id)->slug('neon-void')->one()
            : null;
        if (!$entry) {
            $this->out('→ skip', 'Neon Void entry not found');
            return;
        }

        $entry->setFieldValues([
            'contentBlocks' => $this->buildNestChain(1, $levels),
        ]);

        if (Craft::$app->getElements()->saveElement($entry)) {
            $this->out('✓ chain', "Neon Void → {$levels} distinctly-named levels");
        } else {
            $this->err('chain', 'Neon Void', $entry->getErrors());
        }
    }

    /**
     * Builds the serialized Matrix value for the chain. Each level holds the
     * next inside its own Matrix field; the leaf carries body text.
     */
    private function buildNestChain(int $level, int $max): array
    {
        $lvl = $this->chainLevels[$level];

        if ($lvl['isLeaf']) {
            return [
                "new:{$lvl['typeHandle']}" => ['type' => $lvl['typeHandle'], 'fields' => [
                    $lvl['labelHandle'] => "{$lvl['name']} — rock bottom",
                    $lvl['bodyHandle']  => "You walked down {$max} distinctly-named Matrix levels to read this. If X-Ray named each one correctly, the recursion holds. 🪆",
                ]],
            ];
        }

        return [
            "new:{$lvl['typeHandle']}" => ['type' => $lvl['typeHandle'], 'fields' => [
                $lvl['labelHandle']  => "{$lvl['name']} (level {$level} of {$max})",
                $lvl['matrixHandle'] => $this->buildNestChain($level + 1, $max),
            ]],
        ];
    }

    // ─── Realistic nest: Cars → Attributes → Detail attributes ──────────────────

    /**
     * Seeds a semantic 3-level Matrix nest onto "Speed Kings: Turbo":
     *
     *   contentBlocks
     *     └─ Car            carName + carSpecs (Matrix)
     *          └─ Attribute attributeName + attributeDetails (Matrix)
     *               └─ Detail   detailKey + detailValue   (leaf)
     *
     * i.e. a list of cars, each with multiple attribute groups, each holding
     * more nested detail attributes (body colour, wheels, 0–100, …).
     *
     * Run with: php craft x-ray/setup/cars
     */
    public function actionCars(): int
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $this->stdout("\n🚗  Cars → Attributes → Details nest\n", Console::FG_CYAN);
        $this->stdout(str_repeat('─', 45) . "\n", Console::FG_GREY);

        $this->createCarBlocks();
        $this->seedCars();

        $this->stdout("\n✅  Car nest ready on Speed Kings: Turbo.\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /** Builds the Car / Attribute / Detail block types (deepest-first). */
    private function createCarBlocks(): void
    {
        $this->stdout("\nCar Blocks\n", Console::FG_YELLOW);

        $fields  = Craft::$app->getFields();
        $entries = Craft::$app->entries;

        // ── Leaf: Detail (key + value) ──────────────────────────────────────
        $detailType = $entries->getEntryTypeByHandle('carDetail');
        if (!$detailType) {
            $detailKey   = $this->ensurePlainText('detailKey', 'Detail', ['charLimit' => 120]);
            $detailValue = $this->ensurePlainText('detailValue', 'Value', ['charLimit' => 200]);
            $detailType  = $this->makeBlockType('Detail', 'carDetail', [$detailKey, $detailValue]);
            $this->out('✓ block type', 'Detail (leaf)');
        } else {
            $this->out('→ skip', 'Detail (exists)');
        }

        // ── Attribute (name + nested Detail matrix) ─────────────────────────
        $attributeType = $entries->getEntryTypeByHandle('carAttribute');
        if (!$attributeType) {
            $attributeName = $this->ensurePlainText('attributeName', 'Attribute Group', ['charLimit' => 120]);
            $detailsMatrix = new Matrix(['name' => 'Attribute Details', 'handle' => 'attributeDetails']);
            $detailsMatrix->setEntryTypes([$detailType->id]);
            $fields->saveField($detailsMatrix);
            $attributeType = $this->makeBlockType('Attribute', 'carAttribute', [$attributeName, $detailsMatrix]);
            $this->out('✓ block type', 'Attribute → Detail');
        } else {
            $this->out('→ skip', 'Attribute (exists)');
        }

        // ── Car (name + nested Attribute matrix) ────────────────────────────
        $carType = $entries->getEntryTypeByHandle('car');
        if (!$carType) {
            $carName    = $this->ensurePlainText('carName', 'Car Name', ['charLimit' => 160]);
            $specsMatrix = new Matrix(['name' => 'Car Specs', 'handle' => 'carSpecs']);
            $specsMatrix->setEntryTypes([$attributeType->id]);
            $fields->saveField($specsMatrix);
            $carType = $this->makeBlockType('Car', 'car', [$carName, $specsMatrix]);
            $this->out('✓ block type', 'Car → Attribute');
        } else {
            $this->out('→ skip', 'Car (exists)');
        }

        // ── Graft Car onto Content Blocks ───────────────────────────────────
        $contentBlocks = $fields->getFieldByHandle('contentBlocks');
        if ($contentBlocks instanceof Matrix) {
            $types   = $contentBlocks->getEntryTypes();
            $handles = array_map(fn($t) => $t->handle, $types);
            if (!in_array('car', $handles, true)) {
                $types[] = $carType;
                $contentBlocks->setEntryTypes($types);
                $fields->saveField($contentBlocks);
                $this->out('✓ link', 'Car → Content Blocks');
            } else {
                $this->out('→ skip', 'Car already linked');
            }
        }
    }

    /** Seeds (or replaces) the car list on Speed Kings: Turbo. */
    private function seedCars(): void
    {
        $this->stdout("\nCar Content\n", Console::FG_YELLOW);

        $section = Craft::$app->entries->getSectionByHandle('games');
        $entry = $section
            ? Entry::find()->sectionId($section->id)->slug('speed-kings-turbo')->one()
            : null;
        if (!$entry) {
            $this->out('→ skip', 'Speed Kings: Turbo not found');
            return;
        }

        $cars = [
            ['name' => 'Aurora GT-R', 'attrs' => [
                ['group' => 'Exterior',    'details' => [
                    ['Body Color', 'Midnight Pearl'],
                    ['Wheels', '20" Forged Alloy'],
                    ['Finish', 'Matte Clearcoat'],
                ]],
                ['group' => 'Performance', 'details' => [
                    ['0–100 km/h', '2.8s'],
                    ['Top Speed', '340 km/h'],
                    ['Drivetrain', 'AWD'],
                ]],
            ]],
            ['name' => 'Vortex Spyder', 'attrs' => [
                ['group' => 'Exterior',  'details' => [
                    ['Body Color', 'Solar Flare Orange'],
                    ['Roof', 'Retractable Hardtop'],
                ]],
                ['group' => 'Interior',  'details' => [
                    ['Seats', 'Carbon Bucket'],
                    ['Trim', 'Alcantara'],
                ]],
            ]],
            ['name' => 'Tempest Rally', 'attrs' => [
                ['group' => 'Performance', 'details' => [
                    ['0–100 km/h', '3.4s'],
                    ['Top Speed', '295 km/h'],
                ]],
            ]],
        ];

        $carBlocks = [];
        foreach ($cars as $ci => $car) {
            $specs = [];
            foreach ($car['attrs'] as $ai => $attr) {
                $details = [];
                foreach ($attr['details'] as $di => [$key, $value]) {
                    $details["new:d{$ci}_{$ai}_{$di}"] = ['type' => 'carDetail', 'fields' => [
                        'detailKey'   => $key,
                        'detailValue' => $value,
                    ]];
                }
                $specs["new:a{$ci}_{$ai}"] = ['type' => 'carAttribute', 'fields' => [
                    'attributeName'    => $attr['group'],
                    'attributeDetails' => $details,
                ]];
            }
            $carBlocks["new:car{$ci}"] = ['type' => 'car', 'fields' => [
                'carName'  => $car['name'],
                'carSpecs' => $specs,
            ]];
        }

        $entry->setFieldValues(['contentBlocks' => $carBlocks]);

        if (Craft::$app->getElements()->saveElement($entry)) {
            $this->out('✓ cars', 'Speed Kings → ' . count($cars) . ' cars (Car → Attribute → Detail)');
        } else {
            $this->err('cars', 'Speed Kings', $entry->getErrors());
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function out(string $label, string $value): void
    {
        $this->stdout("  " . str_pad($label, 14) . $value . "\n");
    }

    private function err(string $type, string $name, array $errors): void
    {
        $this->stdout("  ✗ $type '$name': " . json_encode($errors) . "\n", Console::FG_RED);
    }
}
