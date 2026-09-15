<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Toggle;

class SellerRequisitesBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'seller-requisites';
    }

    public static function getLabel(): string
    {
        return 'Реквизиты продавца';
    }

    /**
     * Банковские реквизиты — по выбору, выключены по умолчанию.
     *
     * На «Контактах» они нужны (по ним платят по счёту), а на оферте и в политике
     * блок стоял без них; уже вставленные блоки конфига не имеют и остаются как были.
     */
    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Реквизиты продавца')
            ->modalDescription('Данные берутся из настроек компании — правка настроек меняет блок на всех страницах.')
            ->schema([
                Toggle::make('show_bank')
                    ->label('Показывать банковские реквизиты')
                    ->default(false),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return view(
            'filament.forms.components.rich-editor.rich-content-custom-blocks.seller-requisites.preview',
            static::resolveViewData($config),
        )->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return view(
            'filament.forms.components.rich-editor.rich-content-custom-blocks.seller-requisites.index',
            static::resolveViewData($config),
        )->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{
     *     title: string,
     *     legalName: ?string,
     *     inn: ?string,
     *     ogrn: ?string,
     *     ogrnip: ?string,
     *     legalAddress: ?string,
     *     correspondenceAddress: ?string,
     *     email: ?string,
     *     emailHref: ?string,
     *     phone: ?string,
     *     phoneHref: ?string,
     *     bank: array<string, string>
     * }
     */
    private static function resolveViewData(array $config = []): array
    {
        $legalAddress = static::configValue('company.legal_addr');
        $correspondenceAddress = static::configValue('company.correspondence_addr');

        if ($correspondenceAddress === $legalAddress) {
            $correspondenceAddress = null;
        }

        $email = static::configValue('company.public_email');
        $phone = static::configValue('company.phone');

        $bank = (bool) ($config['show_bank'] ?? false)
            ? array_filter([
                'Банк' => static::configValue('company.bank.name'),
                'БИК' => static::configValue('company.bank.bik'),
                'Расчётный счёт' => static::configValue('company.bank.rs'),
                'Корреспондентский счёт' => static::configValue('company.bank.ks'),
            ])
            : [];

        return [
            'title' => 'Продавец / Администрация сайта',
            'legalName' => static::configValue('company.legal_name'),
            'inn' => static::configValue('company.inn'),
            'ogrn' => static::configValue('company.ogrn'),
            'ogrnip' => static::configValue('company.ogrnip'),
            'legalAddress' => $legalAddress,
            'correspondenceAddress' => $correspondenceAddress,
            'email' => $email,
            'emailHref' => filled($email) ? 'mailto:'.$email : null,
            'phone' => $phone,
            'phoneHref' => filled($phone) ? 'tel:'.preg_replace('/[^\d+]+/', '', $phone) : null,
            'bank' => $bank,
        ];
    }

    private static function configValue(string $key): ?string
    {
        $value = trim((string) config($key));

        return $value !== '' ? $value : null;
    }
}
