<?php

namespace App\Support\Products;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;

class SpecsMatchService
{
    /**
     * @param  array<int, int|string>  $productIds
     * @return array<int, array{
     *     spec_name:string,
     *     normalized_name:string,
     *     frequency:int,
     *     sample_values:array<int, string>,
     *     suggested_data_type:string,
     *     suggested_input_type:string,
     *     confidence:string,
     *     confidence_label:string,
     *     suggested_unit_id:?int,
     *     suggested_unit_label:?string,
     *     suggested_unit_confidence:string,
     *     suggested_unit_confidence_label:string,
     *     unit_candidate_ids:array<int, int>,
     *     existing_attribute_id:?int,
     *     existing_attribute_name:?string,
     *     suggested_decision:string
     * }>
     */
    public function buildAttributeCreationSuggestions(array $productIds, int $targetCategoryId): array
    {
        $normalizedProductIds = $this->normalizeProductIds($productIds);

        if ($normalizedProductIds === []) {
            return [];
        }

        $targetCategory = Category::query()
            ->with(['attributeDefs:id,name'])
            ->find($targetCategoryId);

        if (! $targetCategory || ! $targetCategory->isLeaf()) {
            return [];
        }

        $attributeIndex = $this->buildAttributeIndex($targetCategory);
        $globalAttributeIndex = $this->buildGlobalAttributeIndex();
        $products = Product::query()
            ->whereKey($normalizedProductIds)
            ->select(['id', 'name', 'specs'])
            ->get();

        $aggregated = [];

        foreach ($products as $product) {
            foreach ($this->extractSpecs($product->specs) as $specRow) {
                $specName = $this->stringFromMixed($specRow['name'] ?? null);
                $specValue = $this->stringFromMixed($specRow['value'] ?? null);

                if ($specName === null || $specValue === null) {
                    continue;
                }

                $normalizedSpecName = NameNormalizer::normalize($specName);

                if (! $normalizedSpecName || isset($attributeIndex[$normalizedSpecName])) {
                    continue;
                }

                if (! isset($aggregated[$normalizedSpecName])) {
                    $aggregated[$normalizedSpecName] = [
                        'spec_name' => $specName,
                        'normalized_name' => $normalizedSpecName,
                        'frequency' => 0,
                        'sample_values' => [],
                        'all_values' => [],
                        'unit_tokens' => [],
                    ];
                }

                $aggregated[$normalizedSpecName]['frequency']++;

                if (
                    count($aggregated[$normalizedSpecName]['sample_values']) < 5
                    && ! in_array($specValue, $aggregated[$normalizedSpecName]['sample_values'], true)
                ) {
                    $aggregated[$normalizedSpecName]['sample_values'][] = $specValue;
                }

                if (count($aggregated[$normalizedSpecName]['all_values']) < 25) {
                    $aggregated[$normalizedSpecName]['all_values'][] = $specValue;
                }

                $parsedNumbers = $this->parseNumbersWithUnit($specValue);

                if ($parsedNumbers['unit_token'] !== null && count($aggregated[$normalizedSpecName]['unit_tokens']) < 25) {
                    $aggregated[$normalizedSpecName]['unit_tokens'][] = $parsedNumbers['unit_token'];
                }
            }
        }

        $suggestions = [];

        foreach ($aggregated as $row) {
            $guess = $this->guessAttributeTypeFromValues($row['all_values']);
            $unitSuggestion = in_array($guess['data_type'], ['number', 'range'], true)
                ? $this->resolveSuggestedUnitsFromTokens($row['unit_tokens'])
                : [
                    'suggested_unit_id' => null,
                    'suggested_unit_label' => null,
                    'suggested_unit_confidence' => 'low',
                    'unit_candidate_ids' => [],
                ];

            $existingAttribute = $globalAttributeIndex[$row['normalized_name']] ?? null;

            $suggestions[] = [
                'spec_name' => $row['spec_name'],
                'normalized_name' => $row['normalized_name'],
                'frequency' => (int) $row['frequency'],
                'sample_values' => $row['sample_values'],
                'suggested_data_type' => $guess['data_type'],
                'suggested_input_type' => $guess['input_type'],
                'confidence' => $guess['confidence'],
                'confidence_label' => $this->confidenceLabel($guess['confidence']),
                'suggested_unit_id' => $unitSuggestion['suggested_unit_id'],
                'suggested_unit_label' => $unitSuggestion['suggested_unit_label'],
                'suggested_unit_confidence' => $unitSuggestion['suggested_unit_confidence'],
                'suggested_unit_confidence_label' => $this->confidenceLabel($unitSuggestion['suggested_unit_confidence']),
                'unit_candidate_ids' => $unitSuggestion['unit_candidate_ids'],
                'existing_attribute_id' => $existingAttribute?->id ? (int) $existingAttribute->id : null,
                'existing_attribute_name' => $existingAttribute?->name,
                'suggested_decision' => $existingAttribute ? 'link_existing' : 'ignore',
            ];
        }

        usort($suggestions, function (array $left, array $right): int {
            $byFrequency = ((int) $right['frequency']) <=> ((int) $left['frequency']);

            if ($byFrequency !== 0) {
                return $byFrequency;
            }

            return strcmp((string) $left['spec_name'], (string) $right['spec_name']);
        });

        return $suggestions;
    }

    /**
     * @param  array<int, array<string, mixed>>  $decisionRows
     * @return array{
     *     name_map:array<string, int>,
     *     ignored_spec_names:array<int, string>,
     *     issues:array<int, array{
     *         code:string,
     *         severity:string,
     *         message:string,
     *         row_snapshot:array<string, mixed>|null
     *     }>
     * }
     */
    public function resolveAttributeDecisions(int $targetCategoryId, array $decisionRows, bool $applyChanges): array
    {
        $targetCategory = Category::query()->find($targetCategoryId);

        if (! $targetCategory || ! $targetCategory->isLeaf()) {
            return [
                'name_map' => [],
                'ignored_spec_names' => [],
                'issues' => [[
                    'code' => 'target_category_not_leaf',
                    'severity' => 'error',
                    'message' => 'Целевая категория не найдена или не является конечной (leaf).',
                    'row_snapshot' => [
                        'target_category_id' => $targetCategoryId,
                    ],
                ]],
            ];
        }

        $nameMap = [];
        $ignoredSpecNames = [];
        $issues = [];
        $nextOrder = max(0, (int) DB::table('category_attribute')
            ->where('category_id', $targetCategoryId)
            ->max('filter_order')) + 1;

        foreach ($decisionRows as $decisionRow) {
            if (! is_array($decisionRow)) {
                continue;
            }

            $specName = $this->stringFromMixed($decisionRow['spec_name'] ?? null);
            $normalizedSpecName = NameNormalizer::normalize($specName);

            if ($specName === null || $normalizedSpecName === null) {
                continue;
            }

            $decision = $this->normalizeDecision($decisionRow['decision'] ?? null);
            $baseSnapshot = [
                'spec_name' => $specName,
                'normalized_spec_name' => $normalizedSpecName,
                'decision' => $decision,
            ];

            if ($decision === 'ignore') {
                $ignoredSpecNames[] = $specName;
                $issues[] = [
                    'code' => 'attribute_creation_skipped',
                    'severity' => 'info',
                    'message' => "Спецификация '{$specName}' пропущена по решению администратора.",
                    'row_snapshot' => $baseSnapshot + [
                        'reason' => 'ignored_by_admin',
                    ],
                ];

                continue;
            }

            if ($decision === 'link_existing') {
                $attributeId = (int) ($decisionRow['link_attribute_id'] ?? 0);
                $attribute = $attributeId > 0
                    ? Attribute::query()->find($attributeId)
                    : null;

                if (! $attribute) {
                    $issues[] = [
                        'code' => 'attribute_creation_skipped',
                        'severity' => 'warning',
                        'message' => "Не удалось связать '{$specName}': выбранный атрибут не найден.",
                        'row_snapshot' => $baseSnapshot + [
                            'reason' => 'linked_attribute_not_found',
                            'link_attribute_id' => $attributeId,
                        ],
                    ];

                    continue;
                }

                $nameMap[$normalizedSpecName] = (int) $attribute->getKey();

                if ($applyChanges) {
                    $isAttached = $this->attachAttributeToCategory(
                        category: $targetCategory,
                        attributeId: (int) $attribute->getKey(),
                        order: $nextOrder,
                    );

                    if ($isAttached) {
                        $nextOrder++;
                    }
                }

                continue;
            }

            $dataType = $this->normalizeDataType($decisionRow['create_data_type'] ?? null);
            $inputType = $this->normalizeInputType($decisionRow['create_input_type'] ?? null, $dataType);
            $isNumericAttribute = in_array($dataType, ['number', 'range'], true);
            $baseUnit = null;
            $additionalUnitIds = $this->normalizeUnitIds($decisionRow['create_additional_unit_ids'] ?? []);

            if ($isNumericAttribute) {
                $baseUnitId = (int) ($decisionRow['create_unit_id'] ?? 0);

                if ($baseUnitId <= 0) {
                    $issues[] = [
                        'code' => 'attribute_creation_skipped',
                        'severity' => 'warning',
                        'message' => "Не удалось создать '{$specName}': для number/range атрибута требуется базовая единица.",
                        'row_snapshot' => $baseSnapshot + [
                            'reason' => 'missing_unit_for_numeric_attribute',
                            'data_type' => $dataType,
                            'input_type' => $inputType,
                        ],
                    ];

                    continue;
                }

                $baseUnit = Unit::query()->find($baseUnitId);

                if (! $baseUnit) {
                    $issues[] = [
                        'code' => 'attribute_creation_skipped',
                        'severity' => 'warning',
                        'message' => "Не удалось создать '{$specName}': выбранная единица #{$baseUnitId} не найдена.",
                        'row_snapshot' => $baseSnapshot + [
                            'reason' => 'selected_unit_not_found',
                            'unit_id' => $baseUnitId,
                        ],
                    ];

                    continue;
                }

                $additionalUnitIds = $this->normalizeAdditionalUnitIds(
                    baseUnit: $baseUnit,
                    additionalUnitIds: $additionalUnitIds,
                );
            }

            if (! $applyChanges) {
                $issues[] = [
                    'code' => 'attribute_creation_skipped',
                    'severity' => 'info',
                    'message' => "Dry-run: атрибут '{$specName}' будет создан при apply.",
                    'row_snapshot' => $baseSnapshot + [
                        'reason' => 'dry_run',
                        'data_type' => $dataType,
                        'input_type' => $inputType,
                        'unit_id' => $baseUnit?->id,
                        'additional_unit_ids' => $additionalUnitIds,
                    ],
                ];

                continue;
            }

            $attribute = Attribute::query()->create([
                'name' => $specName,
                'data_type' => $dataType,
                'input_type' => $inputType,
                'unit_id' => $baseUnit?->id,
                'dimension' => $baseUnit?->dimension,
                'is_filterable' => true,
                'is_comparable' => false,
                'sort_order' => 0,
            ]);

            if ($baseUnit) {
                $attribute->syncUnitsFromIds(array_values(array_unique(array_merge(
                    [(int) $baseUnit->id],
                    $additionalUnitIds,
                ))));
            }

            $this->attachAttributeToCategory(
                category: $targetCategory,
                attributeId: (int) $attribute->getKey(),
                order: $nextOrder,
            );
            $nextOrder++;

            $nameMap[$normalizedSpecName] = (int) $attribute->getKey();
            $issues[] = [
                'code' => 'attribute_created_from_spec',
                'severity' => 'info',
                'message' => "Создан атрибут '{$attribute->name}' из спецификации '{$specName}'.",
                'row_snapshot' => $baseSnapshot + [
                    'attribute_id' => (int) $attribute->getKey(),
                    'attribute_name' => $attribute->name,
                    'data_type' => $attribute->data_type,
                    'input_type' => $attribute->input_type,
                    'unit_id' => $attribute->unit_id,
                    'additional_unit_ids' => $additionalUnitIds,
                ],
            ];
        }

        return [
            'name_map' => $nameMap,
            'ignored_spec_names' => array_values(array_unique($ignoredSpecNames)),
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<int, int|string>  $productIds
     * @param  array<string, mixed>  $options
     * @return array{
     *     processed:int,
     *     matched_pav:int,
     *     matched_pao:int,
     *     skipped:int,
     *     issues:int,
     *     fatal_error:?string,
     *     fatal_code:?string
     * }
     */
    public function run(ImportRun $run, array $productIds, array $options = []): array
    {
        $options = $this->normalizeOptions($options);
        $normalizedProductIds = $this->normalizeProductIds($productIds);

        $result = [
            'processed' => 0,
            'matched_pav' => 0,
            'matched_pao' => 0,
            'skipped' => 0,
            'issues' => 0,
            'fatal_error' => null,
            'fatal_code' => null,
        ];
        $result['issues'] += $this->registerPreflightIssues($run, $options['preflight_issues']);

        $targetCategory = Category::query()
            ->with([
                'attributeDefs' => function ($query): void {
                    $query->with(['options', 'unit', 'units']);
                },
            ])
            ->find($options['target_category_id']);

        if (! $targetCategory || ! $targetCategory->isLeaf()) {
            $message = 'Целевая категория не найдена или не является конечной (leaf).';
            $result['issues'] += $this->addIssue(
                run: $run,
                productId: null,
                code: 'target_category_not_leaf',
                message: $message,
                severity: 'error',
                rowSnapshot: [
                    'target_category_id' => $options['target_category_id'],
                ],
            );
            $result['fatal_error'] = $message;
            $result['fatal_code'] = 'target_category_not_leaf';

            return $result;
        }

        $attributeIndex = $this->buildAttributeIndex($targetCategory);
        $result['issues'] += $this->mergeMappedAttributesIntoIndex(
            run: $run,
            targetCategoryId: (int) $targetCategory->getKey(),
            attributeIndex: $attributeIndex,
            attributeNameMap: $options['attribute_name_map'],
        );
        $optionIndexes = [];
        $nextOptionSortOrders = [];

        $products = Product::query()
            ->whereKey($normalizedProductIds)
            ->with(['attributeValues', 'attributeOptions', 'categories'])
            ->get();

        $stagingCategoryId = $this->resolveStagingCategoryId($options);

        foreach ($products as $product) {
            $result['processed']++;

            if (! $options['dry_run']) {
                $this->assignTargetCategoryAsPrimary(
                    product: $product,
                    targetCategoryId: (int) $targetCategory->getKey(),
                );
            }

            if (! $product->primaryCategory()) {
                $result['issues'] += $this->addIssue(
                    run: $run,
                    productId: (int) $product->getKey(),
                    code: 'product_has_no_primary_category',
                    message: 'У товара отсутствует основная категория.',
                    severity: 'info',
                    rowSnapshot: [
                        'product_id' => (int) $product->getKey(),
                        'product_name' => $product->name,
                    ],
                );
            }

            $specRows = $this->extractSpecs($product->specs);
            $existingValuesByAttributeId = $product->attributeValues->keyBy(
                fn (ProductAttributeValue $value): int => (int) $value->attribute_id
            );
            $existingOptionsByAttributeId = $product->attributeOptions->groupBy(
                fn (AttributeOption $option): int => (int) $option->pivot->attribute_id
            );
            $matchedInCurrentRun = [];
            $hasSuccessfulWrites = false;

            foreach ($specRows as $specRow) {
                $specName = $this->stringFromMixed($specRow['name'] ?? null);
                $specValue = $this->stringFromMixed($specRow['value'] ?? null);
                $specSource = $this->stringFromMixed($specRow['source'] ?? null);

                $specSnapshot = [
                    'product_id' => (int) $product->getKey(),
                    'product_name' => $product->name,
                    'spec_name' => $specName,
                    'spec_value' => $specValue,
                    'spec_source' => $specSource,
                ];

                $normalizedSpecName = NameNormalizer::normalize($specName);

                if (! $normalizedSpecName || ! isset($attributeIndex[$normalizedSpecName])) {
                    $result['skipped']++;

                    if ($normalizedSpecName && isset($options['ignored_spec_names'][$normalizedSpecName])) {
                        continue;
                    }

                    $result['issues'] += $this->addIssue(
                        run: $run,
                        productId: (int) $product->getKey(),
                        code: 'spec_name_unmatched',
                        message: "Не найден атрибут для спецификации '{$specName}'.",
                        severity: 'warning',
                        rowSnapshot: $specSnapshot,
                    );

                    continue;
                }

                $attribute = $attributeIndex[$normalizedSpecName];
                $attributeId = (int) $attribute->getKey();

                $hasExisting = isset($matchedInCurrentRun[$attributeId])
                    || ($attribute->usesOptions()
                        ? ($existingOptionsByAttributeId[$attributeId] ?? collect())->isNotEmpty()
                        : $this->hasExistingPavValue($existingValuesByAttributeId->get($attributeId), $attribute));

                if ($this->shouldSkipExisting($hasExisting, $options)) {
                    $result['skipped']++;
                    $result['issues'] += $this->addIssue(
                        run: $run,
                        productId: (int) $product->getKey(),
                        code: 'skipped_existing_value',
                        message: "Атрибут '{$attribute->name}' пропущен, так как значение уже заполнено.",
                        severity: 'info',
                        rowSnapshot: $specSnapshot + [
                            'attribute_id' => $attributeId,
                            'attribute_name' => $attribute->name,
                        ],
                    );

                    continue;
                }

                if ($attribute->usesOptions()) {
                    $candidates = $this->extractOptionCandidates($specValue);

                    if ($candidates === []) {
                        $result['skipped']++;
                        $result['issues'] += $this->addIssue(
                            run: $run,
                            productId: (int) $product->getKey(),
                            code: 'spec_value_parse_failed',
                            message: "Не удалось разобрать значение '{$specValue}' для опционного атрибута '{$attribute->name}'.",
                            severity: 'warning',
                            rowSnapshot: $specSnapshot + [
                                'attribute_id' => $attributeId,
                                'attribute_name' => $attribute->name,
                            ],
                        );

                        continue;
                    }

                    if (! isset($optionIndexes[$attributeId])) {
                        [$optionIndexes[$attributeId], $nextOptionSortOrders[$attributeId]] = $this->buildOptionIndex($attribute);
                    }

                    $matchedOptionIds = [];
                    $missingCandidates = [];

                    foreach ($candidates as $candidate) {
                        $lookupKey = $this->normalizeLookupToken($candidate);

                        if ($lookupKey && isset($optionIndexes[$attributeId][$lookupKey])) {
                            $matchedOptionIds[] = (int) $optionIndexes[$attributeId][$lookupKey]->getKey();

                            continue;
                        }

                        if (! $options['auto_create_options']) {
                            $missingCandidates[] = $candidate;

                            continue;
                        }

                        if ((bool) $options['dry_run']) {
                            $result['issues'] += $this->addIssue(
                                run: $run,
                                productId: (int) $product->getKey(),
                                code: 'option_auto_created',
                                message: "Будет создана новая опция '{$candidate}' для атрибута '{$attribute->name}'.",
                                severity: 'info',
                                rowSnapshot: $specSnapshot + [
                                    'attribute_id' => $attributeId,
                                    'attribute_name' => $attribute->name,
                                    'created_option_value' => $candidate,
                                ],
                            );

                            $syntheticOptionId = -1 * max(1, abs((int) crc32($attributeId.'|'.$candidate)));
                            $matchedOptionIds[] = $syntheticOptionId;

                            continue;
                        }

                        $createdOption = AttributeOption::query()->firstOrCreate(
                            [
                                'attribute_id' => $attributeId,
                                'value' => $candidate,
                            ],
                            [
                                'sort_order' => $nextOptionSortOrders[$attributeId],
                            ],
                        );

                        if ($createdOption->wasRecentlyCreated) {
                            $nextOptionSortOrders[$attributeId] = max(
                                $nextOptionSortOrders[$attributeId] + 1,
                                ((int) $createdOption->sort_order) + 1,
                            );

                            $result['issues'] += $this->addIssue(
                                run: $run,
                                productId: (int) $product->getKey(),
                                code: 'option_auto_created',
                                message: "Создана новая опция '{$createdOption->value}' для атрибута '{$attribute->name}'.",
                                severity: 'info',
                                rowSnapshot: $specSnapshot + [
                                    'attribute_id' => $attributeId,
                                    'attribute_name' => $attribute->name,
                                    'created_option_id' => (int) $createdOption->getKey(),
                                    'created_option_value' => $createdOption->value,
                                ],
                            );
                        }

                        $this->registerOptionInIndex($optionIndexes[$attributeId], $createdOption);
                        $matchedOptionIds[] = (int) $createdOption->getKey();
                    }

                    if ($missingCandidates !== []) {
                        $result['issues'] += $this->addIssue(
                            run: $run,
                            productId: (int) $product->getKey(),
                            code: 'option_not_found',
                            message: 'Не найдены опции: '.implode(', ', $missingCandidates)." для атрибута '{$attribute->name}'.",
                            severity: 'warning',
                            rowSnapshot: $specSnapshot + [
                                'attribute_id' => $attributeId,
                                'attribute_name' => $attribute->name,
                                'missing_options' => $missingCandidates,
                            ],
                        );
                    }

                    $matchedOptionIds = array_values(array_unique(array_map('intval', $matchedOptionIds)));

                    if ($matchedOptionIds === []) {
                        $result['skipped']++;

                        continue;
                    }

                    if (! $options['dry_run']) {
                        if ($attribute->input_type === 'select') {
                            ProductAttributeOption::setSingle(
                                (int) $product->getKey(),
                                $attributeId,
                                (int) $matchedOptionIds[0],
                            );
                        } else {
                            ProductAttributeOption::setForProductAttribute(
                                (int) $product->getKey(),
                                $attributeId,
                                $matchedOptionIds,
                            );
                        }
                    }

                    $matchedInCurrentRun[$attributeId] = true;
                    $hasSuccessfulWrites = true;
                    $result['matched_pao']++;

                    continue;
                }

                $parsedValue = $this->parseValueForPav($attribute, $specValue);

                if (! $parsedValue['ok']) {
                    $result['skipped']++;
                    $result['issues'] += $this->addIssue(
                        run: $run,
                        productId: (int) $product->getKey(),
                        code: (string) ($parsedValue['code'] ?? 'spec_value_parse_failed'),
                        message: (string) ($parsedValue['message'] ?? "Не удалось разобрать значение '{$specValue}'."),
                        severity: 'warning',
                        rowSnapshot: $specSnapshot + [
                            'attribute_id' => $attributeId,
                            'attribute_name' => $attribute->name,
                        ],
                    );

                    continue;
                }

                if (! $options['dry_run']) {
                    $pav = ProductAttributeValue::query()->firstOrNew([
                        'product_id' => (int) $product->getKey(),
                        'attribute_id' => $attributeId,
                    ]);
                    $pav->setTypedValue($attribute, $parsedValue['value']);
                    $pav->attribute()->associate($attribute);
                    $pav->save();

                    $existingValuesByAttributeId->put($attributeId, $pav);
                }

                $matchedInCurrentRun[$attributeId] = true;
                $hasSuccessfulWrites = true;
                $result['matched_pav']++;
            }

            if ($hasSuccessfulWrites && ! $options['dry_run'] && $options['detach_staging_after_success']) {
                $this->detachStagingCategory(
                    product: $product,
                    targetCategoryId: (int) $targetCategory->getKey(),
                    stagingCategoryId: $stagingCategoryId,
                );
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     target_category_id:int,
     *     dry_run:bool,
     *     only_empty_attributes:bool,
     *     overwrite_existing:bool,
     *     auto_create_options:bool,
     *     detach_staging_after_success:bool,
     *     attribute_name_map:array<string, int>,
     *     ignored_spec_names:array<string, bool>,
     *     preflight_issues:array<int, array{
     *         code:string,
     *         severity:string,
     *         message:string,
     *         row_snapshot:array<string, mixed>|null
     *     }>
     * }
     */
    private function normalizeOptions(array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);

        return [
            'target_category_id' => (int) ($options['target_category_id'] ?? 0),
            'dry_run' => $dryRun,
            'only_empty_attributes' => (bool) ($options['only_empty_attributes'] ?? true),
            'overwrite_existing' => (bool) ($options['overwrite_existing'] ?? false),
            'auto_create_options' => (bool) ($options['auto_create_options'] ?? false),
            'detach_staging_after_success' => $dryRun
                ? false
                : (bool) ($options['detach_staging_after_success'] ?? false),
            'attribute_name_map' => $this->normalizeAttributeNameMap($options['attribute_name_map'] ?? []),
            'ignored_spec_names' => $this->normalizeIgnoredSpecNames($options['ignored_spec_names'] ?? []),
            'preflight_issues' => $this->normalizePreflightIssues($options['preflight_issues'] ?? []),
        ];
    }

    /**
     * @param  array<int, int|string>  $productIds
     * @return array<int, int>
     */
    private function normalizeProductIds(array $productIds): array
    {
        return collect($productIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function normalizeAttributeNameMap(mixed $rawMap): array
    {
        if (! is_array($rawMap)) {
            return [];
        }

        $map = [];

        foreach ($rawMap as $specName => $attributeId) {
            if (! is_string($specName)) {
                continue;
            }

            $normalizedSpecName = NameNormalizer::normalize($specName);
            $normalizedAttributeId = (int) $attributeId;

            if (! $normalizedSpecName || $normalizedAttributeId <= 0) {
                continue;
            }

            $map[$normalizedSpecName] = $normalizedAttributeId;
        }

        return $map;
    }

    /**
     * @return array<string, bool>
     */
    private function normalizeIgnoredSpecNames(mixed $rawIgnoredSpecNames): array
    {
        if (! is_array($rawIgnoredSpecNames)) {
            return [];
        }

        $normalized = [];

        foreach ($rawIgnoredSpecNames as $key => $value) {
            $candidate = null;

            if (is_string($value)) {
                $candidate = $value;
            } elseif (is_string($key)) {
                $candidate = $key;
            }

            $normalizedName = NameNormalizer::normalize($candidate);

            if (! $normalizedName) {
                continue;
            }

            $normalized[$normalizedName] = true;
        }

        return $normalized;
    }

    /**
     * @return array<int, array{
     *     code:string,
     *     severity:string,
     *     message:string,
     *     row_snapshot:array<string, mixed>|null
     * }>
     */
    private function normalizePreflightIssues(mixed $rawIssues): array
    {
        if (! is_array($rawIssues)) {
            return [];
        }

        $issues = [];

        foreach ($rawIssues as $rawIssue) {
            if (! is_array($rawIssue)) {
                continue;
            }

            $code = $this->stringFromMixed($rawIssue['code'] ?? null);
            $message = $this->stringFromMixed($rawIssue['message'] ?? null);
            $severity = $this->stringFromMixed($rawIssue['severity'] ?? null) ?? 'info';
            $severity = in_array($severity, ['info', 'warning', 'error'], true) ? $severity : 'info';
            $rowSnapshot = $rawIssue['row_snapshot'] ?? null;

            if ($code === null || $message === null) {
                continue;
            }

            $issues[] = [
                'code' => $code,
                'severity' => $severity,
                'message' => $message,
                'row_snapshot' => is_array($rowSnapshot) ? $rowSnapshot : null,
            ];
        }

        return $issues;
    }

    /**
     * @param  array<int, array{
     *     code:string,
     *     severity:string,
     *     message:string,
     *     row_snapshot:array<string, mixed>|null
     * }>  $issues
     */
    private function registerPreflightIssues(ImportRun $run, array $issues): int
    {
        $added = 0;

        foreach ($issues as $issue) {
            $added += $this->addIssue(
                run: $run,
                productId: null,
                code: $issue['code'],
                message: $issue['message'],
                severity: $issue['severity'],
                rowSnapshot: $issue['row_snapshot'],
            );
        }

        return $added;
    }

    /**
     * @param  array<string, Attribute>  $attributeIndex
     * @param  array<string, int>  $attributeNameMap
     */
    private function mergeMappedAttributesIntoIndex(
        ImportRun $run,
        int $targetCategoryId,
        array &$attributeIndex,
        array $attributeNameMap,
    ): int {
        if ($attributeNameMap === []) {
            return 0;
        }

        $mappedAttributeIds = array_values(array_unique(array_map('intval', array_values($attributeNameMap))));

        if ($mappedAttributeIds === []) {
            return 0;
        }

        $mappedAttributes = Attribute::query()
            ->with(['options', 'unit', 'units'])
            ->whereIn('id', $mappedAttributeIds)
            ->get()
            ->keyBy(fn (Attribute $attribute): int => (int) $attribute->getKey());

        $targetCategoryAttributes = DB::table('category_attribute')
            ->where('category_id', $targetCategoryId)
            ->whereIn('attribute_id', $mappedAttributeIds)
            ->pluck('attribute_id')
            ->map(fn ($attributeId): int => (int) $attributeId)
            ->all();
        $targetCategoryAttributeLookup = array_flip($targetCategoryAttributes);

        $issues = 0;

        foreach ($attributeNameMap as $normalizedSpecName => $attributeId) {
            if (! isset($mappedAttributes[$attributeId])) {
                $issues += $this->addIssue(
                    run: $run,
                    productId: null,
                    code: 'attribute_creation_skipped',
                    message: "Не удалось применить сопоставление '{$normalizedSpecName}': атрибут #{$attributeId} не найден.",
                    severity: 'warning',
                    rowSnapshot: [
                        'normalized_spec_name' => $normalizedSpecName,
                        'attribute_id' => $attributeId,
                    ],
                );

                continue;
            }

            $attribute = $mappedAttributes[$attributeId];
            $attributeIndex[$normalizedSpecName] = $attribute;

            if (! isset($targetCategoryAttributeLookup[$attributeId])) {
                $issues += $this->addIssue(
                    run: $run,
                    productId: null,
                    code: 'attribute_not_in_target_category',
                    message: "Атрибут '{$attribute->name}' не привязан к целевой категории, сопоставление выполнено только для предпросмотра.",
                    severity: 'info',
                    rowSnapshot: [
                        'normalized_spec_name' => $normalizedSpecName,
                        'attribute_id' => $attributeId,
                        'attribute_name' => $attribute->name,
                        'target_category_id' => $targetCategoryId,
                    ],
                );
            }
        }

        return $issues;
    }

    private function normalizeDecision(mixed $decision): string
    {
        $decision = is_string($decision) ? trim($decision) : '';

        return match ($decision) {
            'link_existing', 'create_attribute', 'ignore' => $decision,
            default => 'ignore',
        };
    }

    private function normalizeDataType(mixed $dataType): string
    {
        $dataType = is_string($dataType) ? trim($dataType) : '';

        return in_array($dataType, ['text', 'number', 'boolean', 'range'], true)
            ? $dataType
            : 'text';
    }

    private function normalizeInputType(mixed $inputType, string $dataType): string
    {
        $inputType = is_string($inputType) ? trim($inputType) : '';

        $allowedInputTypes = match ($dataType) {
            'number' => ['number'],
            'range' => ['range'],
            'boolean' => ['boolean'],
            default => ['multiselect', 'select'],
        };

        if (! in_array($inputType, $allowedInputTypes, true)) {
            return $allowedInputTypes[0];
        }

        return $inputType;
    }

    /**
     * @return array<int, int>
     */
    private function normalizeUnitIds(mixed $rawUnitIds): array
    {
        if (! is_array($rawUnitIds)) {
            return [];
        }

        return collect($rawUnitIds)
            ->map(fn ($unitId): int => (int) $unitId)
            ->filter(fn (int $unitId): bool => $unitId > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $additionalUnitIds
     * @return array<int, int>
     */
    private function normalizeAdditionalUnitIds(Unit $baseUnit, array $additionalUnitIds): array
    {
        if ($additionalUnitIds === []) {
            return [];
        }

        return Unit::query()
            ->whereIn('id', $additionalUnitIds)
            ->where('id', '!=', (int) $baseUnit->id)
            ->when(
                $baseUnit->dimension !== null,
                fn ($query) => $query->where('dimension', $baseUnit->dimension),
            )
            ->pluck('id')
            ->map(fn ($unitId): int => (int) $unitId)
            ->values()
            ->all();
    }

    private function attachAttributeToCategory(Category $category, int $attributeId, int $order): bool
    {
        $alreadyAttached = DB::table('category_attribute')
            ->where('category_id', (int) $category->getKey())
            ->where('attribute_id', $attributeId)
            ->exists();

        $category->attributeDefs()->syncWithoutDetaching([
            $attributeId => [
                'is_required' => false,
                'filter_order' => $order,
                'compare_order' => $order,
                'visible_in_specs' => true,
                'visible_in_compare' => true,
                'group_override' => null,
            ],
        ]);

        return ! $alreadyAttached;
    }

    /**
     * @param  array<int, string>  $values
     * @return array{data_type:string, input_type:string, confidence:string}
     */
    private function guessAttributeTypeFromValues(array $values): array
    {
        $values = collect($values)
            ->map(fn ($value): string => is_string($value) ? trim($value) : '')
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();

        if ($values === []) {
            return [
                'data_type' => 'text',
                'input_type' => 'text',
                'confidence' => 'low',
            ];
        }

        $totalValues = count($values);
        $booleanHits = 0;
        $numberHits = 0;
        $rangeHits = 0;
        $multiselectSignals = 0;
        $distinctOptionTokens = [];

        foreach ($values as $value) {
            if ($this->parseBooleanToken($value) !== null) {
                $booleanHits++;
            }

            $parsedNumbers = $this->parseNumbersWithUnit($value);

            if ($parsedNumbers['numbers'] !== []) {
                $numberHits++;

                if (count($parsedNumbers['numbers']) > 1 || $this->looksLikeRangeValue($value)) {
                    $rangeHits++;
                }
            }

            $optionCandidates = $this->extractOptionCandidates($value);

            if (count($optionCandidates) > 1) {
                $multiselectSignals++;
            }

            foreach ($optionCandidates as $optionCandidate) {
                $lookupToken = $this->normalizeLookupToken($optionCandidate);

                if ($lookupToken) {
                    $distinctOptionTokens[$lookupToken] = true;
                }
            }
        }

        if ($booleanHits === $totalValues) {
            return [
                'data_type' => 'boolean',
                'input_type' => 'boolean',
                'confidence' => 'high',
            ];
        }

        if (($rangeHits / $totalValues) >= 0.6) {
            return [
                'data_type' => 'range',
                'input_type' => 'range',
                'confidence' => $rangeHits === $totalValues ? 'high' : 'medium',
            ];
        }

        if (($numberHits / $totalValues) >= 0.7) {
            return [
                'data_type' => 'number',
                'input_type' => 'number',
                'confidence' => $numberHits === $totalValues ? 'high' : 'medium',
            ];
        }

        $distinctOptionCount = count($distinctOptionTokens);

        if ($totalValues >= 2 && $distinctOptionCount > 0 && $distinctOptionCount <= max(6, (int) ceil($totalValues * 0.6))) {
            return [
                'data_type' => 'text',
                'input_type' => $multiselectSignals > 0 ? 'multiselect' : 'select',
                'confidence' => 'medium',
            ];
        }

        return [
            'data_type' => 'text',
            'input_type' => 'text',
            'confidence' => 'low',
        ];
    }

    private function confidenceLabel(string $confidence): string
    {
        return match ($confidence) {
            'high' => 'Высокая',
            'medium' => 'Средняя',
            default => 'Низкая',
        };
    }

    /**
     * @param  array<int, string>  $rawUnitTokens
     * @return array{
     *     suggested_unit_id:?int,
     *     suggested_unit_label:?string,
     *     suggested_unit_confidence:string,
     *     unit_candidate_ids:array<int, int>
     * }
     */
    private function resolveSuggestedUnitsFromTokens(array $rawUnitTokens): array
    {
        $normalizedTokens = collect($rawUnitTokens)
            ->map(fn ($unitToken): ?string => is_string($unitToken) ? $this->normalizeLookupToken($unitToken) : null)
            ->filter(fn (?string $token): bool => $token !== null)
            ->values()
            ->all();

        if ($normalizedTokens === []) {
            return [
                'suggested_unit_id' => null,
                'suggested_unit_label' => null,
                'suggested_unit_confidence' => 'low',
                'unit_candidate_ids' => [],
            ];
        }

        $scores = [];
        $unitsById = [];

        foreach ($normalizedTokens as $token) {
            $units = $this->resolveUnitsByLookupToken($token);

            foreach ($units as $unit) {
                $unitId = (int) $unit->getKey();
                $scores[$unitId] = ($scores[$unitId] ?? 0) + 1;
                $unitsById[$unitId] = $unit;
            }
        }

        if ($scores === []) {
            return [
                'suggested_unit_id' => null,
                'suggested_unit_label' => null,
                'suggested_unit_confidence' => 'low',
                'unit_candidate_ids' => [],
            ];
        }

        arsort($scores);
        $candidateIds = array_map('intval', array_keys($scores));

        $topUnitId = $candidateIds[0] ?? null;

        if (! $topUnitId || ! isset($unitsById[$topUnitId])) {
            return [
                'suggested_unit_id' => null,
                'suggested_unit_label' => null,
                'suggested_unit_confidence' => 'low',
                'unit_candidate_ids' => array_slice($candidateIds, 0, 8),
            ];
        }

        $topScore = (int) ($scores[$topUnitId] ?? 0);
        $secondUnitId = $candidateIds[1] ?? null;
        $secondScore = $secondUnitId ? (int) ($scores[$secondUnitId] ?? 0) : 0;
        $tokenCount = count($normalizedTokens);
        $coverage = $tokenCount > 0 ? ($topScore / $tokenCount) : 0.0;

        $confidence = 'low';

        if ($coverage >= 0.8 && $topScore > $secondScore) {
            $confidence = 'high';
        } elseif ($coverage >= 0.6 && $topScore > $secondScore) {
            $confidence = 'medium';
        }

        $suggestedUnitId = in_array($confidence, ['high', 'medium'], true) ? $topUnitId : null;
        $suggestedUnit = $suggestedUnitId ? ($unitsById[$suggestedUnitId] ?? null) : null;

        return [
            'suggested_unit_id' => $suggestedUnitId,
            'suggested_unit_label' => $this->unitLabel($suggestedUnit),
            'suggested_unit_confidence' => $confidence,
            'unit_candidate_ids' => array_slice($candidateIds, 0, 8),
        ];
    }

    /**
     * @return array<int, Unit>
     */
    private function resolveUnitsByLookupToken(string $lookupToken): array
    {
        $unitLookupIndex = $this->unitLookupIndex();

        if (! isset($unitLookupIndex[$lookupToken])) {
            return [];
        }

        return array_values($unitLookupIndex[$lookupToken]);
    }

    /**
     * @return array<string, array<int, Unit>>
     */
    private function unitLookupIndex(): array
    {
        $index = [];
        $units = Unit::query()
            ->select(['id', 'name', 'symbol', 'base_symbol', 'dimension'])
            ->get();

        foreach ($units as $unit) {
            foreach ($this->unitLookupTokens($unit) as $token) {
                $index[$token] ??= [];
                $index[$token][(int) $unit->getKey()] = $unit;
            }
        }

        return $index;
    }

    private function unitLabel(?Unit $unit): ?string
    {
        if (! $unit) {
            return null;
        }

        $label = $unit->name;

        if ($unit->symbol) {
            $label .= ' ('.$unit->symbol.')';
        }

        if ($unit->dimension) {
            $label .= ' — '.$unit->dimension;
        }

        return $label;
    }

    /**
     * @return array<string, Attribute>
     */
    private function buildAttributeIndex(Category $category): array
    {
        $index = [];

        foreach ($category->attributeDefs as $attribute) {
            $normalizedName = NameNormalizer::normalize($attribute->name);

            if (! $normalizedName || isset($index[$normalizedName])) {
                continue;
            }

            $index[$normalizedName] = $attribute;
        }

        return $index;
    }

    /**
     * @return array<string, Attribute>
     */
    private function buildGlobalAttributeIndex(): array
    {
        $index = [];
        $attributes = Attribute::query()
            ->select(['id', 'name'])
            ->orderBy('id')
            ->get();

        foreach ($attributes as $attribute) {
            $normalizedName = NameNormalizer::normalize($attribute->name);

            if (! $normalizedName || isset($index[$normalizedName])) {
                continue;
            }

            $index[$normalizedName] = $attribute;
        }

        return $index;
    }

    /**
     * @return array<int, array{name:string, value:string, source:?string}>
     */
    private function extractSpecs(mixed $rawSpecs): array
    {
        if (is_string($rawSpecs)) {
            $decoded = json_decode($rawSpecs, true);

            if (is_array($decoded)) {
                $rawSpecs = $decoded;
            }
        }

        if (! is_array($rawSpecs)) {
            return [];
        }

        $specs = [];

        foreach ($rawSpecs as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->stringFromMixed($row['name'] ?? null);
            $value = $this->stringFromMixed($row['value'] ?? null);

            if ($name === null || $value === null) {
                continue;
            }

            $specs[] = [
                'name' => $name,
                'value' => $value,
                'source' => $this->stringFromMixed($row['source'] ?? null),
            ];
        }

        return $specs;
    }

    private function stringFromMixed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            $normalized = trim((string) $value);

            return $normalized === '' ? null : $normalized;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            $values = collect($value)
                ->map(function (mixed $item): ?string {
                    if (is_string($item) || is_int($item) || is_float($item)) {
                        $item = trim((string) $item);

                        return $item === '' ? null : $item;
                    }

                    if (is_bool($item)) {
                        return $item ? '1' : '0';
                    }

                    return null;
                })
                ->filter(fn (?string $item): bool => $item !== null)
                ->values()
                ->all();

            if ($values === []) {
                return null;
            }

            return implode(', ', array_unique($values));
        }

        return null;
    }

    private function shouldSkipExisting(bool $hasExisting, array $options): bool
    {
        if (! $hasExisting) {
            return false;
        }

        if ((bool) $options['overwrite_existing']) {
            return false;
        }

        if ((bool) $options['only_empty_attributes']) {
            return true;
        }

        return true;
    }

    private function hasExistingPavValue(?ProductAttributeValue $value, Attribute $attribute): bool
    {
        if (! $value) {
            return false;
        }

        if ($attribute->data_type === 'boolean') {
            return $value->value_boolean !== null;
        }

        if ($attribute->data_type === 'number') {
            return $value->value_number !== null || $value->value_si !== null;
        }

        if ($attribute->data_type === 'range') {
            return $value->value_min !== null
                || $value->value_max !== null
                || $value->value_min_si !== null
                || $value->value_max_si !== null;
        }

        return is_string($value->value_text) && trim($value->value_text) !== '';
    }

    /**
     * @return array{ok:bool, value?:mixed, code?:string, message?:string}
     */
    private function parseValueForPav(Attribute $attribute, string $rawValue): array
    {
        if ($attribute->data_type === 'boolean') {
            $parsedBoolean = $this->parseBooleanToken($rawValue);

            if ($parsedBoolean !== null) {
                return ['ok' => true, 'value' => $parsedBoolean];
            }

            return [
                'ok' => false,
                'code' => 'spec_value_parse_failed',
                'message' => "Не удалось интерпретировать '{$rawValue}' как boolean.",
            ];
        }

        if ($attribute->data_type === 'number' || $attribute->data_type === 'range') {
            $parsedNumbers = $this->parseNumbersWithUnit($rawValue);

            if ($parsedNumbers['numbers'] === []) {
                return [
                    'ok' => false,
                    'code' => 'spec_value_parse_failed',
                    'message' => "Не удалось извлечь число из '{$rawValue}'.",
                ];
            }

            $unitToken = $parsedNumbers['unit_token'];
            $numbers = $parsedNumbers['numbers'];

            if ($attribute->data_type === 'number') {
                $converted = $this->convertToAttributeUnit($attribute, $numbers[0], $unitToken);

                if (! $converted['ok']) {
                    return $converted;
                }

                return [
                    'ok' => true,
                    'value' => $converted['value'],
                ];
            }

            $min = $numbers[0];
            $max = $numbers[1] ?? $numbers[0];

            $convertedMin = $this->convertToAttributeUnit($attribute, $min, $unitToken);
            if (! $convertedMin['ok']) {
                return $convertedMin;
            }

            $convertedMax = $this->convertToAttributeUnit($attribute, $max, $unitToken);
            if (! $convertedMax['ok']) {
                return $convertedMax;
            }

            $rangeMin = (float) $convertedMin['value'];
            $rangeMax = (float) $convertedMax['value'];

            if ($rangeMin > $rangeMax) {
                [$rangeMin, $rangeMax] = [$rangeMax, $rangeMin];
            }

            return [
                'ok' => true,
                'value' => [
                    'min' => $rangeMin,
                    'max' => $rangeMax,
                ],
            ];
        }

        $text = trim($rawValue);

        if ($text === '') {
            return [
                'ok' => false,
                'code' => 'spec_value_parse_failed',
                'message' => 'Значение текстового атрибута пустое.',
            ];
        }

        return [
            'ok' => true,
            'value' => $text,
        ];
    }

    private function parseBooleanToken(string $rawValue): ?bool
    {
        $normalized = NameNormalizer::normalize($rawValue) ?? mb_strtolower(trim($rawValue), 'UTF-8');

        if (in_array($normalized, ['1', 'true', 'yes', 'да', 'есть', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'нет', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function looksLikeRangeValue(string $rawValue): bool
    {
        $normalized = str_replace(['−', '—', '–'], '-', $rawValue);

        return preg_match('/\d+\s*-\s*\d+/u', $normalized) === 1;
    }

    /**
     * @return array{numbers:array<int, float>, unit_token:?string}
     */
    private function parseNumbersWithUnit(string $rawValue): array
    {
        $normalized = str_replace([
            "\xC2\xA0",
            "\xE2\x80\xAF",
            '−',
            '—',
            '–',
        ], [
            ' ',
            ' ',
            '-',
            '-',
            '-',
        ], $rawValue);

        $normalized = preg_replace('/(?<=\d)\s*-\s*(?=\d)/u', ' ', $normalized) ?? $normalized;

        preg_match_all('/[-+]?\d+(?:[.,]\d+)?/u', $normalized, $matches);

        $numbers = collect($matches[0] ?? [])
            ->map(function (string $chunk): ?float {
                $chunk = str_replace(',', '.', $chunk);

                if (! is_numeric($chunk)) {
                    return null;
                }

                return (float) $chunk;
            })
            ->filter(fn (?float $number): bool => $number !== null)
            ->values()
            ->all();

        return [
            'numbers' => $numbers,
            'unit_token' => $this->extractUnitToken($normalized),
        ];
    }

    private function extractUnitToken(string $rawValue): ?string
    {
        $withoutNumbers = preg_replace('/[-+]?\d+(?:[.,]\d+)?/u', ' ', $rawValue) ?? $rawValue;
        $withoutNumbers = str_replace(['-', '—', '–', '~', '(', ')', ':'], ' ', $withoutNumbers);
        $withoutNumbers = preg_replace('/\s+/u', ' ', trim($withoutNumbers)) ?? trim($withoutNumbers);

        if ($withoutNumbers === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $withoutNumbers) ?: [];
        $last = end($parts);

        if (! is_string($last)) {
            return null;
        }

        $last = trim($last, " \t\n\r\0\x0B,.;");

        return $last === '' ? null : $last;
    }

    /**
     * @return array{ok:bool, value?:float, code?:string, message?:string}
     */
    private function convertToAttributeUnit(Attribute $attribute, float $value, ?string $unitToken): array
    {
        if ($unitToken === null) {
            return [
                'ok' => true,
                'value' => $value,
            ];
        }

        $resolvedUnit = $this->resolveUnitByToken($attribute, $unitToken);

        if (! $resolvedUnit) {
            return [
                'ok' => false,
                'code' => 'unit_ambiguous',
                'message' => "Не удалось сопоставить единицу '{$unitToken}' для атрибута '{$attribute->name}'.",
            ];
        }

        if (! $attribute->defaultUnit()) {
            return [
                'ok' => false,
                'code' => 'unit_ambiguous',
                'message' => "У атрибута '{$attribute->name}' не задана базовая единица для конвертации.",
            ];
        }

        $si = $attribute->toSiWithUnit($value, $resolvedUnit);

        if ($si === null) {
            return [
                'ok' => false,
                'code' => 'unit_ambiguous',
                'message' => "Не удалось конвертировать значение '{$value}' ({$unitToken}).",
            ];
        }

        $converted = $attribute->fromSi($si);

        if ($converted === null) {
            return [
                'ok' => false,
                'code' => 'unit_ambiguous',
                'message' => "Не удалось привести значение '{$value}' к единице атрибута '{$attribute->name}'.",
            ];
        }

        return [
            'ok' => true,
            'value' => (float) $converted,
        ];
    }

    private function resolveUnitByToken(Attribute $attribute, string $unitToken): ?Unit
    {
        $lookupToken = $this->normalizeLookupToken($unitToken);

        if (! $lookupToken) {
            return null;
        }

        $units = $attribute->relationLoaded('units')
            ? $attribute->units
            : $attribute->units()->get();

        if ($attribute->unit && ! $units->contains(fn (Unit $unit): bool => (int) $unit->getKey() === (int) $attribute->unit->getKey())) {
            $units = $units->prepend($attribute->unit);
        }

        $matches = $units->filter(function (Unit $unit) use ($lookupToken): bool {
            return in_array($lookupToken, $this->unitLookupTokens($unit), true);
        })->values();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function unitLookupTokens(Unit $unit): array
    {
        $tokens = [
            $this->normalizeLookupToken($unit->symbol),
            $this->normalizeLookupToken($unit->name),
            $this->normalizeLookupToken($unit->base_symbol),
        ];

        return array_values(array_filter(array_unique($tokens)));
    }

    private function normalizeLookupToken(?string $value): ?string
    {
        $normalized = NameNormalizer::normalize($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = strtr($normalized, [
            '⁰' => '0',
            '¹' => '1',
            '²' => '2',
            '³' => '3',
            '⁴' => '4',
            '⁵' => '5',
            '⁶' => '6',
            '⁷' => '7',
            '⁸' => '8',
            '⁹' => '9',
            '₀' => '0',
            '₁' => '1',
            '₂' => '2',
            '₃' => '3',
            '₄' => '4',
            '₅' => '5',
            '₆' => '6',
            '₇' => '7',
            '₈' => '8',
            '₉' => '9',
        ]);

        $normalized = str_replace([' ', '.'], '', $normalized);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function extractOptionCandidates(string $rawValue): array
    {
        $rawValue = trim($rawValue);

        if ($rawValue === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;|\||\n|\r)+\s*/u', $rawValue) ?: [];

        if (count($parts) === 1 && str_contains($rawValue, ' / ')) {
            $parts = preg_split('/\s+\/\s+/u', $rawValue) ?: $parts;
        }

        $parts = array_map(static fn (string $value): string => trim($value), $parts);
        $parts = array_values(array_filter($parts, static fn (string $value): bool => $value !== ''));

        return array_values(array_unique($parts));
    }

    /**
     * @return array{0:array<string, AttributeOption>, 1:int}
     */
    private function buildOptionIndex(Attribute $attribute): array
    {
        $index = [];
        $maxSortOrder = 0;

        foreach ($attribute->options as $option) {
            $this->registerOptionInIndex($index, $option);
            $maxSortOrder = max($maxSortOrder, (int) $option->sort_order);
        }

        return [$index, $maxSortOrder + 1];
    }

    /**
     * @param  array<string, AttributeOption>  $index
     */
    private function registerOptionInIndex(array &$index, AttributeOption $option): void
    {
        $lookupToken = $this->normalizeLookupToken($option->value);

        if (! $lookupToken || isset($index[$lookupToken])) {
            return;
        }

        $index[$lookupToken] = $option;
    }

    /**
     * @param  array<string, mixed>|null  $rowSnapshot
     */
    private function addIssue(
        ImportRun $run,
        ?int $productId,
        string $code,
        string $message,
        string $severity,
        ?array $rowSnapshot = null,
    ): int {
        $run->issues()->create([
            'row_index' => $productId,
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'row_snapshot' => $rowSnapshot,
        ]);

        return 1;
    }

    private function assignTargetCategoryAsPrimary(Product $product, int $targetCategoryId): void
    {
        $product->categories()->syncWithoutDetaching([
            $targetCategoryId => ['is_primary' => false],
        ]);

        $product->setPrimaryCategory($targetCategoryId);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveStagingCategoryId(array $options): ?int
    {
        if ($options['dry_run'] || ! $options['detach_staging_after_success']) {
            return null;
        }

        $stagingSlug = config('catalog-export.staging_category_slug', 'staging');

        if (! is_string($stagingSlug) || trim($stagingSlug) === '') {
            return null;
        }

        $stagingCategoryId = Category::query()
            ->where('slug', trim($stagingSlug))
            ->value('id');

        return $stagingCategoryId ? (int) $stagingCategoryId : null;
    }

    private function detachStagingCategory(Product $product, int $targetCategoryId, ?int $stagingCategoryId): void
    {
        if (! $stagingCategoryId || $stagingCategoryId === $targetCategoryId) {
            return;
        }

        $hasStaging = $product->categories()
            ->where('categories.id', $stagingCategoryId)
            ->exists();

        if (! $hasStaging) {
            return;
        }

        $hasTarget = $product->categories()
            ->where('categories.id', $targetCategoryId)
            ->exists();

        if (! $hasTarget) {
            $product->categories()->attach($targetCategoryId, ['is_primary' => false]);
        }

        $isStagingPrimary = $product->categories()
            ->where('categories.id', $stagingCategoryId)
            ->wherePivot('is_primary', true)
            ->exists();

        $product->categories()->detach($stagingCategoryId);

        if ($isStagingPrimary) {
            $product->setPrimaryCategory($targetCategoryId);
        }
    }
}
