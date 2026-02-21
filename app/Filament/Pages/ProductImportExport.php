<?php

namespace App\Filament\Pages;

use App\Models\ImportRun;
use App\Models\Product;
use App\Support\Products\ProductExportService;
use App\Support\Products\ProductImportService;
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
use UnitEnum;

class ProductImportExport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Импорт/Экспорт';

    protected static ?string $navigationLabel = 'Импорт/Экспорт товаров';

    protected static ?string $title = 'Импорт/Экспорт товаров в Excel';

    protected string $view = 'filament.pages.product-import-export';

    /** @var array{
     *     export_columns: array<int, string>,
     *     import_file: TemporaryUploadedFile|array<int, TemporaryUploadedFile|string>|string|null,
     *     import_file_original_name: string|null
     * }|null
     */
    public ?array $data = [
        'export_columns' => [],
        'import_file' => null,
        'import_file_original_name' => null,
    ];

    /** @var array<string, int>|null */
    public ?array $dryRunTotals = null;

    /** @var array<int, array<string, mixed>> */
    public array $dryRunPreviewCreate = [];

    /** @var array<int, array<string, mixed>> */
    public array $dryRunPreviewUpdate = [];

    /** @var array<int, array<string, mixed>> */
    public array $dryRunPreviewConflict = [];

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    public function mount(ProductExportService $export): void
    {
        $forced = $export->forcedColumns();
        $defaults = $export->defaultColumns();

        $visibleDefaults = array_values(array_diff($defaults, $forced));

        $this->form->fill([
            'export_columns' => $visibleDefaults,
            'import_file' => null,
            'import_file_original_name' => null,
        ]);

        $this->dryRunTotals = null;
        $this->dryRunPreviewCreate = [];
        $this->dryRunPreviewUpdate = [];
        $this->dryRunPreviewConflict = [];
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
                ->url('https://help.stankoman.ru/import/excel-import/', true),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $fields = config('catalog-export.fields');
        $forced = config('catalog-export.forced_columns', []);
        $options = [];

        foreach ($fields as $key => $meta) {
            if (in_array($key, $forced, true)) {
                continue;
            }

            $options[$key] = $meta['header'] ?? $key;
        }

        return $schema
            ->components([
                Section::make('Экспорт в Excel')
                    ->schema([
                        Select::make('export_columns')
                            ->label('Колонки')
                            ->options($options)
                            ->multiple()
                            ->searchable()
                            ->required()
                            ->hintIcon(Heroicon::InformationCircle, 'Обязательные служебные колонки добавляются автоматически, даже если они не выбраны.')
                            ->helperText('Если колонка не выбрана, она не попадёт в файл. Обязательные будут добавлены автоматически.'),
                        Actions::make([
                            FormAction::make('export')
                                ->label('Скачать файл XLSX')
                                ->action('doExport')
                                ->color('primary'),
                        ]),
                    ]),

                Section::make('Импорт без применения изменений')
                    ->schema([
                        FileUpload::make('import_file')
                            ->label('Файл Excel для импорта')
                            ->hintIcon(Heroicon::InformationCircle, 'Поддерживается только формат XLSX. Файл сначала проверяется, а затем может быть применён.')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                            ->preserveFilenames()
                            ->disk('local')
                            ->directory('imports')
                            ->visibility('private')
                            ->helperText('Загрузите XLSX-файл, затем нажмите «Проверить файл без применения».')
                            ->required(),
                        Actions::make([
                            FormAction::make('dryrun')
                                ->label('Проверить файл без применения')
                                ->action('doDryRun')
                                ->color('success'),
                        ]),
                    ]),

                Section::make('Применение импорта')
                    ->schema([
                        Actions::make([
                            FormAction::make('apply_import')
                                ->label('Применить последний загруженный Excel')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->disabled(fn (): bool => ! $this->canApplyLastRun())
                                ->action('doApply'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function doExport(ProductExportService $export): void
    {
        $columns = $export->validateColumns($this->data['export_columns'] ?? []);
        $result = $export->exportToXlsx(Product::query(), $columns);

        $token = bin2hex(random_bytes(8));
        $key = "exports/tmp/{$token}.path";
        Storage::disk('local')->put($key, $result['path']);

        $url = route('admin.tools.download-export', [
            'token' => $token,
            'name' => $result['downloadName'],
        ]);

        Notification::make()
            ->title('Файл готов')
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

    public function doDryRun(ProductImportService $import): void
    {
        $fileState = $this->data['import_file'] ?? null;
        $absPath = null;
        $originalName = null;

        if ($fileState instanceof TemporaryUploadedFile) {
            $originalName = $fileState->getClientOriginalName();
            $absPath = $this->storeImportFileAndGetAbsolutePath($fileState);
        } elseif (is_array($fileState)) {
            $first = reset($fileState);

            if ($first instanceof TemporaryUploadedFile) {
                $originalName = $first->getClientOriginalName();
                $absPath = $this->storeImportFileAndGetAbsolutePath($first);
            } elseif (is_string($first) && $first !== '') {
                $originalName = basename($first);
                $absPath = Storage::disk('local')->path($first);
            }
        } elseif (is_string($fileState) && $fileState !== '') {
            $originalName = basename($fileState);
            $absPath = Storage::disk('local')->path($fileState);
        }

        if (! $absPath) {
            Notification::make()
                ->title('Файл не выбран')
                ->danger()
                ->send();

            return;
        }

        $this->data['import_file_original_name'] = $originalName;

        if (! $absPath || ! is_file($absPath)) {
            Notification::make()
                ->title('Не удалось сохранить файл импорта')
                ->danger()
                ->send();

            return;
        }

        $run = ImportRun::query()->create([
            'type' => 'products',
            'status' => 'pending',
            'columns' => null,
            'totals' => null,
            'source_filename' => $originalName ?: basename($absPath),
            'stored_path' => $absPath,
            'user_id' => Auth::id(),
            'started_at' => now(),
        ]);

        $result = $import->dryRunFromXlsx($run, $absPath);

        $totals = $result['totals'] ?? [];
        $preview = $result['preview'] ?? ['create' => [], 'update' => [], 'conflict' => []];

        $this->dryRunTotals = $totals;
        $this->dryRunPreviewCreate = $preview['create'] ?? [];
        $this->dryRunPreviewUpdate = $preview['update'] ?? [];
        $this->dryRunPreviewConflict = $preview['conflict'] ?? [];

        $unchanged = $totals['same'] ?? 0;

        Notification::make()
            ->title('Dry-run завершён')
            ->body("Создастся: {$totals['create']}, обновится: {$totals['update']}, без изменений: {$unchanged}, конфликтов: {$totals['conflict']}, ошибок: {$totals['error']}")
            ->success()
            ->send();
    }

    protected function storeImportFileAndGetAbsolutePath(TemporaryUploadedFile $file): ?string
    {
        $tmpPath = $file->getRealPath();

        if (! $tmpPath || ! is_file($tmpPath)) {
            return null;
        }

        $importsDir = storage_path('app/imports');

        if (! is_dir($importsDir)) {
            if (! @mkdir($importsDir, 0775, true) && ! is_dir($importsDir)) {
                return null;
            }
        }

        $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'xlsx';
        $filename = uniqid('import_', true).'.'.$extension;
        $targetPath = $importsDir.DIRECTORY_SEPARATOR.$filename;

        if (! @copy($tmpPath, $targetPath)) {
            return null;
        }

        return $targetPath;
    }

    public function doApply(ProductImportService $import): void
    {
        $run = ImportRun::query()
            ->where('type', 'products')
            ->latest('id')
            ->first();

        if (! $run) {
            Notification::make()
                ->title('Нет запусков импорта')
                ->body('Сначала выполните dry-run.')
                ->danger()
                ->send();

            return;
        }

        if ($run->status !== 'dry_run') {
            Notification::make()
                ->title('Нельзя применить импорт')
                ->body("Последний запуск #{$run->id} имеет статус '{$run->status}'. Ожидается 'dry_run'.")
                ->warning()
                ->send();

            return;
        }

        $absPath = $run->stored_path ?? null;

        if (! $absPath || ! is_file($absPath)) {
            Notification::make()
                ->title('Файл импорта не найден')
                ->body($absPath ?: 'Путь к файлу отсутствует.')
                ->danger()
                ->send();

            return;
        }

        $totals = $import->applyFromXlsx($run, $absPath, [
            'write' => true,
        ]);

        Notification::make()
            ->title('Импорт применён')
            ->body(
                'Создано: '.($totals['created'] ?? 0).', '.
                    'обновлено: '.($totals['updated'] ?? 0).', '.
                    'без изменений: '.($totals['same'] ?? 0).', '.
                    'конфликтов: '.($totals['conflict'] ?? 0).', '.
                    'ошибок: '.($totals['error'] ?? 0)
            )
            ->success()
            ->send();
    }

    protected function canApplyLastRun(): bool
    {
        $run = ImportRun::query()
            ->where('type', 'products')
            ->latest('id')
            ->first();

        return $run !== null && $run->status === 'dry_run';
    }
}
