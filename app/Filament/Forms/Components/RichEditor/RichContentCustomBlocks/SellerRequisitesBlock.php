<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Forms\Components\RichEditor\RichContentCustomBlock;

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
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return view(
            'filament.forms.components.rich-editor.rich-content-custom-blocks.seller-requisites.preview',
            static::resolveViewData(),
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
            static::resolveViewData(),
        )->render();
    }

    /**
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
     *     phoneHref: ?string
     * }
     */
    private static function resolveViewData(): array
    {
        $legalAddress = static::configValue('company.legal_addr');
        $correspondenceAddress = static::configValue('company.correspondence_addr');

        if ($correspondenceAddress === $legalAddress) {
            $correspondenceAddress = null;
        }

        $email = static::configValue('company.public_email');
        $phone = static::configValue('company.phone');

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
        ];
    }

    private static function configValue(string $key): ?string
    {
        $value = trim((string) config($key));

        return $value !== '' ? $value : null;
    }
}
