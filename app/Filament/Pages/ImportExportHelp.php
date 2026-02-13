<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Str;

class ImportExportHelp extends Page
{
    protected static ?string $title = 'Инструкция по импорту/экспорту товаров';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'import-export-help';

    protected string $view = 'filament.pages.import-export-help';

    public string $guideHtml = '';

    public function mount(): void
    {
        $this->guideHtml = Str::markdown($this->guideMarkdown(), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    private function guideMarkdown(): string
    {
        $path = base_path('IMPORT_EXPORT_EXCEL_GUIDE.md');

        if (! is_file($path)) {
            return '# Инструкция недоступна'."\n\n".'Файл `IMPORT_EXPORT_EXCEL_GUIDE.md` не найден.';
        }

        $content = file_get_contents($path);

        if ($content === false || blank($content)) {
            return '# Инструкция недоступна'."\n\n".'Файл `IMPORT_EXPORT_EXCEL_GUIDE.md` пустой или не читается.';
        }

        return $content;
    }
}
