<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Tests;

use Flute\Modules\ConnectHistory\Services\ServerBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Отказ №6 из docs/AUTOPSY.md: поле additional было объектом при заполненном
 * значении и массивом при пустом, а читалось всегда как объект — отсюда
 * "Attempt to read property on array". Плюс Json::decode бросал на битом JSON.
 *
 * Проверяемое свойство: тип результата НЕ зависит от того, что пришло на вход.
 */
final class ServerBindingTest extends TestCase
{
    #[DataProvider('inputs')]
    public function testShapeIsAlwaysTheSame(mixed $input): void
    {
        $result = ServerBinding::readAdditional($input);

        self::assertArrayHasKey('server_id', $result);
        self::assertArrayHasKey('prefix', $result);
        self::assertIsInt($result['server_id']);
        self::assertIsString($result['prefix']);
        self::assertNotSame('', $result['prefix']);
    }

    public static function inputs(): array
    {
        return [
            'null' => [null],
            'пустая строка' => [''],
            'пробелы' => ['   '],
            'битый JSON' => ['{'],
            'обрезанный JSON' => ['{"server_id":'],
            'JSON-массив' => ['[]'],
            'JSON-строка' => ['"текст"'],
            'JSON-число' => ['42'],
            'пустой объект' => ['{}'],
            'готовый массив' => [['server_id' => 2]],
            'объект' => [(object) ['server_id' => 2]],
            'число вместо строки' => [42],
            'булево' => [true],
            'вложенный массив в server_id' => [['server_id' => ['вложено']]],
        ];
    }

    public function testServerIdIsRead(): void
    {
        self::assertSame(3, ServerBinding::readAdditional('{"server_id":3}')['server_id']);
        self::assertSame(3, ServerBinding::readAdditional('{"server_id":"3"}')['server_id']);
    }

    /**
     * Старый модуль хранил номер сервера под ключом sid. Читаем и его —
     * это дешевле, чем объяснять админу, почему подключение «пустое».
     */
    public function testLegacySidKeyIsUnderstood(): void
    {
        self::assertSame(5, ServerBinding::readAdditional('{"sid":5}')['server_id']);
    }

    public function testServerIdWinsOverLegacySid(): void
    {
        self::assertSame(9, ServerBinding::readAdditional('{"server_id":9,"sid":5}')['server_id']);
    }

    public function testMissingServerIdBecomesZeroNotError(): void
    {
        self::assertSame(0, ServerBinding::readAdditional('{}')['server_id']);
    }

    public function testCustomPrefixIsPreserved(): void
    {
        self::assertSame('proj2_', ServerBinding::readAdditional('{"prefix":"proj2_"}')['prefix']);
    }

    /**
     * Префикс попадает в SQL как часть имени таблицы и параметризован быть не может,
     * поэтому проходит белый список.
     */
    #[DataProvider('prefixes')]
    public function testPrefixWhitelist(mixed $input, string $expected): void
    {
        self::assertSame($expected, ServerBinding::sanitizePrefix($input));
    }

    public static function prefixes(): array
    {
        return [
            'обычный' => ['ch_', 'ch_'],
            'другой проект' => ['proj2_', 'proj2_'],
            'без подчёркивания' => ['ch', 'ch'],
            'пустой -> дефолт' => ['', 'ch_'],
            'null -> дефолт' => [null, 'ch_'],
            'не строка -> дефолт' => [42, 'ch_'],
            'обратная кавычка' => ['ch`_', 'ch_'],
            'точка с запятой' => ['ch_;DROP TABLE x;--', 'ch_'],
            'кавычка' => ["ch'", 'ch_'],
            'пробел' => ['ch _', 'ch_'],
            'дефис' => ['ch-', 'ch_'],
            'перенос строки' => ["ch_\n", 'ch_'],
            'слишком длинный' => [str_repeat('a', 17), 'ch_'],
            'ровно 16' => [str_repeat('a', 16), str_repeat('a', 16)],
        ];
    }

    public function testPrepareNormalises(): void
    {
        self::assertSame(
            ['server_id' => 2, 'prefix' => 'ch_'],
            ServerBinding::prepare(['server_id' => '2', 'prefix' => 'плохой префикс'])
        );

        self::assertSame(
            ['server_id' => 0, 'prefix' => 'ch_'],
            ServerBinding::prepare([])
        );

        self::assertSame(
            ['server_id' => 4, 'prefix' => 'stats_'],
            ServerBinding::prepare(['server_id' => 4, 'prefix' => 'stats_'])
        );
    }

    public function testServerIdIsRequiredByValidation(): void
    {
        $rules = ServerBinding::validationRules();

        self::assertArrayHasKey('server_id', $rules);
        self::assertContains('required', $rules['server_id']);
    }

    /**
     * Панель сохраняет в additional ТОЛЬКО те ключи, что перечислены в правилах
     * валидации (HandlesDbActions::saveDbConnection). Поле, забытое в правилах,
     * молча выбрасывается: форма его показывает, человек заполняет, а в базу
     * оно не доезжает.
     *
     * Именно так потерялся prefix в первом релизе.
     */
    public function testEveryPreparedKeyIsPersistable(): void
    {
        $prepared = array_keys(ServerBinding::prepare(['server_id' => 1, 'prefix' => 'ch_']));
        $persistable = array_keys(ServerBinding::validationRules());

        self::assertSame(
            [],
            array_values(array_diff($prepared, $persistable)),
            'ключ, которого нет в validationRules(), не сохранится в additional'
        );
    }

    /**
     * Обратная сторона: правило без соответствующего поля в prepare() означает,
     * что панель ждёт значение, которого драйвер никогда не отдаст.
     */
    public function testEveryRuleHasAPreparedValue(): void
    {
        $prepared = array_keys(ServerBinding::prepare([]));
        $persistable = array_keys(ServerBinding::validationRules());

        self::assertSame([], array_values(array_diff($persistable, $prepared)));
    }

    /**
     * Правила задаются массивами, как во всём ядре Flute: строку с '|' валидатор
     * панели не разбирает на отдельные проверки.
     */
    public function testRulesAreArrays(): void
    {
        foreach (ServerBinding::validationRules() as $field => $rules) {
            self::assertIsArray($rules, "правила для {$field} должны быть массивом");
            self::assertNotSame([], $rules);
        }
    }
}
