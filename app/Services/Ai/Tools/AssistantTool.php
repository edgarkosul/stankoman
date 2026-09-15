<?php

namespace App\Services\Ai\Tools;

/**
 * Инструмент ассистента.
 *
 * Отдельными классами, а не методами агента: набор инструментов отличается
 * между магазинами (в intertooler своя выдача товаров и своя заявка), а цикл
 * tool-use — нет. Агент перебирает реестр и про конкретные модели проекта
 * ничего не знает.
 *
 * Возвращаемая строка уходит модели как результат вызова. Поэтому она должна
 * читаться моделью, а не человеком: короткая, фактическая, без вежливостей.
 * Ошибку тоже возвращаем текстом, а не исключением, — модель обязана уметь
 * сказать «не получилось уточнить», а не уронить весь ход.
 */
interface AssistantTool
{
    public function name(): string;

    /**
     * Описание в формате OpenAI function calling.
     *
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function run(array $arguments, ToolContext $context): string;
}
