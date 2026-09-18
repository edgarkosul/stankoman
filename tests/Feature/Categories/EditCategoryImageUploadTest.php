<?php

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');

    $user = User::factory()->create();

    config([
        'settings.general.filament_admin_emails' => [strtolower((string) $user->email)],
    ]);

    $this->actingAs($user);
});

function categoryForImageUploadTest(?string $img = null): Category
{
    return Category::query()->create([
        'name' => 'Категория с картинкой',
        'slug' => 'category-image-upload-'.Str::random(8),
        'parent_id' => -1,
        'order' => (int) Category::query()->where('parent_id', -1)->max('order') + 1,
        'is_active' => true,
        'img' => $img,
    ]);
}

test('uploaded image goes to the category folder and is saved on the edit page', function (): void {
    $category = categoryForImageUploadTest();

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->assertSee('Загрузить файл')
        ->callAction('uploadCategoryImage', ['image' => UploadedFile::fake()->image('category.jpg', 800, 600)])
        ->assertHasNoActionErrors()
        ->call('save')
        ->assertHasNoFormErrors();

    $img = $category->refresh()->img;

    expect($img)->toStartWith(Category::IMAGE_UPLOAD_DIRECTORY.'/');
    Storage::disk('public')->assertExists($img);
});

test('image can be uploaded while creating a category', function (): void {
    Livewire::test(CreateCategory::class)
        ->assertSee('Загрузить файл')
        ->fillForm([
            'name' => 'Новая категория',
            'slug' => 'category-image-upload-on-create',
        ])
        ->callAction('uploadCategoryImage', ['image' => UploadedFile::fake()->image('category.jpg')])
        ->assertHasNoActionErrors()
        ->call('create')
        ->assertHasNoFormErrors();

    $img = Category::query()->where('slug', 'category-image-upload-on-create')->value('img');

    expect($img)->toStartWith(Category::IMAGE_UPLOAD_DIRECTORY.'/');
    Storage::disk('public')->assertExists($img);
});

test('product image picked for a category survives replacing, clearing and deleting the category', function (): void {
    Storage::disk('public')->put('pics/product-photo.jpg', 'product');

    $replaced = categoryForImageUploadTest('pics/product-photo.jpg');

    Livewire::test(EditCategory::class, ['record' => $replaced->getRouteKey()])
        ->callAction('uploadCategoryImage', ['image' => UploadedFile::fake()->image('category.jpg')])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($replaced->refresh()->img)->toStartWith(Category::IMAGE_UPLOAD_DIRECTORY.'/');

    $cleared = categoryForImageUploadTest('pics/product-photo.jpg');

    Livewire::test(EditCategory::class, ['record' => $cleared->getRouteKey()])
        ->call('clearCategoryImage')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($cleared->refresh()->img)->toBeNull();

    categoryForImageUploadTest('pics/product-photo.jpg')->delete();

    Storage::disk('public')->assertExists('pics/product-photo.jpg');
});

test('replacing an uploaded image removes the old upload only after saving', function (): void {
    $oldPath = Category::IMAGE_UPLOAD_DIRECTORY.'/old.jpg';
    Storage::disk('public')->put($oldPath, 'old');

    $category = categoryForImageUploadTest($oldPath);

    $page = Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->callAction('uploadCategoryImage', ['image' => UploadedFile::fake()->image('new.jpg')]);

    Storage::disk('public')->assertExists($oldPath);

    $page->call('save')->assertHasNoFormErrors();

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($category->refresh()->img);
});

test('unsaved upload is discarded when it is replaced before saving', function (): void {
    $category = categoryForImageUploadTest();

    $page = Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->callAction('uploadCategoryImage', ['image' => UploadedFile::fake()->image('first.jpg')]);

    $firstPath = $page->get('data.img');

    $page->callAction('uploadCategoryImage', ['image' => UploadedFile::fake()->image('second.jpg')]);

    $secondPath = $page->get('data.img');

    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($secondPath);

    $page->call('selectCategoryImage', 'pics/product-photo.jpg');

    Storage::disk('public')->assertMissing($secondPath);

    $page->call('save')->assertHasNoFormErrors();

    expect($category->refresh()->img)->toBe('pics/product-photo.jpg');
});
