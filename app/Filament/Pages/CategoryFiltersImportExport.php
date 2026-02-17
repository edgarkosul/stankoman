<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\Category;
use App\Models\ImportRun;
use App\Support\Products\CategoryFilterImportService;
use App\Support\Products\CategoryFilterSchemaService;
use App\Support\Products\CategoryFilterTemplateExportService;
use BackedEnum;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Throwable;
use UnitEnum;

class CategoryFiltersImportExport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static string|UnitEnum|null $navigationGroup = 'Импорт/Экспорт';

    protected static ?string $navigationLabel = 'Фильтры (экспорт и импорт)';

    protected static ?string $title = 'Фильтры (экспорт и импорт)';

    protected string $view = 'filament.pages.category-filters-import-export';

    /** @var array{
     *     category_id: int|string|null,
     *     import_file: TemporaryUploadedFile|array<int|string, mixed>|string|null
     * }|null
     */
    public ?array $data = [
        'category_id' => null,
        'import_file' => null,
    ];

    /** @var array<string, int>|null */
    public ?array $lastTotals = null;

    public ?int $lastRunId = null;

    public ?bool $lastWriteMode = null;

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    public function mount(): void
    {
        $this->form->fill([
            'category_id' => null,
            'import_file' => null,
        ]);

        $this->lastTotals = null;
        $this->lastRunId = null;
        $this->lastWriteMode = null;
    }

    /**
     * @return array<FormAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            FormAction::make('instructions')
                ->label('Инструкция')
                ->icon('heroicon-o-question-mark-circle')
                ->color('gray')
                ->url(ImportExportHelp::getUrl()),
            FormAction::make('history')
                ->label('История импортов')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(ImportRunResource::getUrl()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Параметры')
                    ->schema([
                        Select::make('category_id')
                            ->label('Листовая категория')
                            ->searchable()
                            ->hintIcon(Heroicon::InformationCircle, 'Экспорт и импорт шаблона доступны только для листовой категории. При выборе XLSX категория подставится автоматически.')
                            ->options(
                                Category::query()
                                    ->leaf()
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                            )
                            ->placeholder('Выберите категорию'),
                    ]),

                Section::make('Экспорт шаблона фильтров')
                    ->schema([
                        Actions::make([
                            FormAction::make('export_template')
                                ->label('Скачать XLSX-шаблон')
                                ->action('doExport')
                                ->color('primary'),
                        ]),
                    ]),

                Section::make('Импорт шаблона фильтров')
                    ->schema([
                        FileUpload::make('import_file')
                            ->label('Файл шаблона XLSX')
                            ->hintIcon(Heroicon::InformationCircle, 'Сначала запустите dry-run, затем примените изменения. Категория подставляется автоматически из файла.')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                            ->preserveFilenames()
                            ->disk('local')
                            ->directory('imports')
                            ->visibility('private')
                            ->afterStateUpdated(function (mixed $state): void {
                                $storedPath = $this->resolveStoredImportPath($state);

                                if (! $storedPath) {
                                    return;
                                }

                                $category = $this->resolveLeafCategoryByTemplate($storedPath);

                                if (! $category) {
                                    return;
                                }

                                $this->data['category_id'] = $category->getKey();
                            })
                            ->required(),
                        Actions::make([
                            FormAction::make('dryrun')
                                ->label('Проверить шаблон (dry-run)')
                                ->color('success')
                                ->action('doDryRun'),
                            FormAction::make('apply')
                                ->label('Применить шаблон')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->action('doApply'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function doExport(CategoryFilterTemplateExportService $export): void
    {
        $category = $this->resolveSelectedLeafCategory();

        if (! $category) {
            Notification::make()
                ->title('Выберите листовую категорию')
                ->danger()
                ->send();

            return;
        }

        $result = $export->export($category);

        $token = bin2hex(random_bytes(8));
        $key = "exports/tmp/{$token}.path";
        Storage::disk('local')->put($key, $result['path']);

        $url = route('admin.tools.download-export', [
            'token' => $token,
            'name' => $result['downloadName'],
        ]);

        Notification::make()
            ->title('Шаблон готов')
            ->success()
            ->actions([
                FormAction::make('download')
                    ->label('Скачать XLSX')
                    ->button()
                    ->url($url)
                    ->openUrlInNewTab(),
            ])
            ->persistent()
            ->send();
    }

    public function doDryRun(CategoryFilterImportService $import): void
    {
        $this->runImport(import: $import, write: false);
    }

    public function doApply(CategoryFilterImportService $import): void
    {
        $this->runImport(import: $import, write: true);
    }

    private function runImport(CategoryFilterImportService $import, bool $write): void
    {
        $storedPath = $this->resolveStoredImportPath($this->data['import_file'] ?? null);

        if (! $storedPath) {
            Notification::make()
                ->title('Файл не выбран')
                ->danger()
                ->send();

            return;
        }

        $category = $this->resolveCategoryForImport($storedPath);

        if (! $category) {
            Notification::make()
                ->title('Не удалось определить категорию')
                ->body('Выберите листовую категорию вручную или используйте корректный XLSX-шаблон фильтров.')
                ->danger()
                ->send();

            return;
        }

        $absPath = $this->resolveAbsoluteImportPath($storedPath);

        if (! is_file($absPath)) {
            Notification::make()
                ->title('Не удалось прочитать загруженный файл')
                ->danger()
                ->send();

            return;
        }

        $run = ImportRun::query()->create([
            'type' => 'category_filters',
            'status' => 'pending',
            'columns' => null,
            'totals' => null,
            'source_filename' => basename($storedPath),
            'stored_path' => $storedPath,
            'user_id' => Auth::id(),
            'started_at' => now(),
        ]);

        $totals = $import->importFromXlsx($run, $category, $absPath, $write);

        $this->lastTotals = $totals;
        $this->lastRunId = $run->id;
        $this->lastWriteMode = $write;

        $updated = (int) ($totals['updated'] ?? $totals['update'] ?? 0);
        $skipped = (int) ($totals['skipped'] ?? $totals['same'] ?? 0);
        $conflicts = (int) ($totals['conflict'] ?? 0);
        $errors = (int) ($totals['error'] ?? 0);
        $scanned = (int) ($totals['scanned'] ?? 0);

        $notification = Notification::make()
            ->title($write ? 'Импорт шаблона применён' : 'Dry-run шаблона завершён')
            ->body("Проверено: {$scanned}, обновлено: {$updated}, пропущено: {$skipped}, конфликтов: {$conflicts}, ошибок: {$errors}. Запуск #{$run->id}.")
            ->actions([
                FormAction::make('history')
                    ->label('История импортов')
                    ->button()
                    ->url(ImportRunResource::getUrl())
                    ->openUrlInNewTab(),
            ])
            ->persistent();

        if ($errors > 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    private function resolveSelectedLeafCategory(): ?Category
    {
        $categoryId = (int) ($this->data['category_id'] ?? 0);

        if ($categoryId <= 0) {
            return null;
        }

        return Category::query()
            ->leaf()
            ->whereKey($categoryId)
            ->first();
    }

    private function resolveStoredImportPath(mixed $state): ?string
    {
        if ($state instanceof TemporaryUploadedFile) {
            return $this->storeTemporaryImportFile($state);
        }

        if (is_string($state) && $state !== '') {
            return $state;
        }

        if (is_array($state)) {
            foreach (['path', 'stored_path', 'storedPath', 'relative_path', 'relativePath'] as $key) {
                $value = $state[$key] ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            $first = reset($state);

            if (is_string($first) && $first !== '' && $this->looksLikeStoredPath($first)) {
                return $first;
            }

            foreach ($state as $value) {
                $resolved = $this->resolveStoredImportPath($value);

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function looksLikeStoredPath(string $candidate): bool
    {
        return str_contains($candidate, '/') || str_contains($candidate, '\\');
    }

    private function storeTemporaryImportFile(TemporaryUploadedFile $file): ?string
    {
        $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'xlsx';
        $filename = uniqid('category_filter_import_', true).'.'.$extension;

        $storedPath = $file->storeAs('imports', $filename, 'local');

        return is_string($storedPath) && $storedPath !== '' ? $storedPath : null;
    }

    private function resolveAbsoluteImportPath(string $storedPath): string
    {
        if (str_starts_with($storedPath, DIRECTORY_SEPARATOR)) {
            return $storedPath;
        }

        return Storage::disk('local')->path($storedPath);
    }

    private function resolveCategoryForImport(string $storedPath): ?Category
    {
        $categoryFromTemplate = $this->resolveLeafCategoryByTemplate($storedPath);

        if ($categoryFromTemplate !== null) {
            $this->data['category_id'] = $categoryFromTemplate->getKey();

            return $categoryFromTemplate;
        }

        return $this->resolveSelectedLeafCategory();
    }

    private function resolveLeafCategoryByTemplate(string $storedPath): ?Category
    {
        $categoryId = $this->detectCategoryIdFromTemplatePath($storedPath);

        if ($categoryId === null) {
            return null;
        }

        return Category::query()
            ->leaf()
            ->whereKey($categoryId)
            ->first();
    }

    private function detectCategoryIdFromTemplatePath(string $storedPath): ?int
    {
        $absPath = $this->resolveAbsoluteImportPath($storedPath);

        if (! is_file($absPath)) {
            return null;
        }

        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);

        try {
            $spreadsheet = $reader->load($absPath);
        } catch (Throwable) {
            return null;
        }

        try {
            $sheet = $spreadsheet->getSheetByName(CategoryFilterSchemaService::META_SHEET);

            if (! $sheet) {
                return null;
            }

            $rows = $sheet->toArray(null, true, false, false);
            $meta = [];

            foreach ($rows as $index => $row) {
                if ($index === 0) {
                    continue;
                }

                $key = trim((string) ($row[0] ?? ''));

                if ($key === '') {
                    continue;
                }

                $meta[$key] = trim((string) ($row[1] ?? ''));
            }

            if (($meta['template_type'] ?? '') !== CategoryFilterSchemaService::TEMPLATE_TYPE) {
                return null;
            }

            $categoryId = (int) ($meta['category_id'] ?? 0);

            return $categoryId > 0 ? $categoryId : null;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
