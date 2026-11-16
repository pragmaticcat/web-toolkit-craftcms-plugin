<?php

namespace pragmatic\webtoolkit\tests\unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\db\LegacyRecord;

class LegacyRecordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = new Capsule();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $database->schema()->create('translation_rows', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('group');
        });
    }

    public function testUpdatesAndDeletesOnlyMatchingRows(): void
    {
        LegacyTranslationRow::query()->insert([
            ['id' => 1, 'group' => 'old'],
            ['id' => 2, 'group' => 'old'],
            ['id' => 3, 'group' => 'keep'],
        ]);

        self::assertSame(2, LegacyTranslationRow::updateAll(['group' => 'new'], ['group' => 'old']));
        self::assertSame('new', LegacyTranslationRow::findOne(1)?->group);
        self::assertSame('keep', LegacyTranslationRow::findOne(['id' => 3])?->group);

        self::assertSame(2, LegacyTranslationRow::deleteAll(['id' => [1, 2]]));
        self::assertSame([3], LegacyTranslationRow::query()->pluck('id')->all());
    }
}

class LegacyTranslationRow extends Model
{
    use LegacyRecord;

    protected $table = 'translation_rows';
    public $timestamps = false;
    protected $guarded = [];
}
