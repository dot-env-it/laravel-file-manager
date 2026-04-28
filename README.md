# Laravel File Manager
[![Latest Stable Version](https://img.shields.io/packagist/v/dot-env-it/laravel-file-manager.svg?style=flat-square)](https://packagist.org/packages/dot-env-it/laravel-file-manager)
[![Total Downloads](https://img.shields.io/packagist/dt/dot-env-it/laravel-file-manager.svg?style=flat-square)](https://packagist.org/packages/dot-env-it/laravel-file-manager)
[![License](https://img.shields.io/packagist/l/dot-env-it/laravel-file-manager.svg?style=flat-square)](https://packagist.org/packages/dot-env-it/laravel-file-manager)

<picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://banners.beyondco.de/Laravel%20File%20Manager.png?pattern=topography&style=style_2&fontSize=100px&md=1&showWatermark=1&theme=dark&packageManager=composer+require&packageName=dot-env-it%2Flaravel-file-manager&description=Metronic+8+styled+file+manager+for+Laravel+using+Spatie+Media+Library.&images=https%3A%2F%2Fwww.php.net%2Fimages%2Flogos%2Fnew-php-logo.svg">
    <img src="https://banners.beyondco.de/Laravel%20File%20Manager.png?pattern=topography&style=style_2&fontSize=100px&md=1&showWatermark=1&theme=light&packageManager=composer+require&packageName=dot-env-it%2Flaravel-file-manager&description=Metronic+8+styled+file+manager+for+Laravel+using+Spatie+Media+Library.&images=https%3A%2F%2Fwww.php.net%2Fimages%2Flogos%2Fnew-php-logo.svg" alt="Laravel File Manager">
</picture>

A professional Livewire-powered file management system for Laravel. Organize media into logical tiers and manage complex file relationships with ease.

-----

## ✨ Features

* **Reactive UI:** Built with Livewire for a smooth, AJAX-driven experience.
* **Hierarchical Navigation:**
    * **Tier 1:** Category Folders (e.g., "Identification", "Certificates").
    * **Tier 2:** Record Folders (e.g., "User: Alice Smith" with item counts and total sizes).
    * **Tier 3:** Detailed File Grid (Support for PDF, Images, and Office docs).
* **Search:** Case-insensitive search across folders and files (PostgreSQL `ILIKE` ready).
* **Spatie Powered:** Built directly on top of [Spatie Media Library](https://spatie.be/docs/laravel-medialibrary).

## 🚀 Installation

Install via composer:

```bash
composer require dot-env-it/laravel-file-manager
```

Publish the config and translations:

```bash
php artisan vendor:publish --tag="file-manager-config"
php artisan vendor:publish --tag="file-manager-translations"
```

## 🛠️ Usage

### 1\. Implement the Interface

Add the `FileManagerModelInterface` to your parent model (e.g., a **Company**, **Department**, or **User Group**) to define how sub-models are mapped.

To keep your models clean and compatible with the **Laravel File Manager**, add these methods to your `User` (or any related model). These methods provide the labels and keys the component uses to build the navigation you see on your Parent Model page.

### 2\. The Parent Model (e.g., Company)

This model defines the "Tier 1" folders.

```php
use DotEnvIt\FileManager\Interfaces\FileManagerModelInterface;

class Company extends Model implements FileManagerModelInterface
{
    /**
     * Define the "Tier 1" structure.
     * Maps a Model Class to a collection of IDs.
     */
    public function getFileManagerMap(): array
    {
        return [
            \App\Models\User::class => $this->users->pluck('id'),
            \App\Models\UserCertificate::class => $this->certificates->pluck('id'),
        ];
    }

    /**
     * The display name for the root folder in breadcrumbs.
     */
    public function getFileManagerLabel(): string
    {
        return $this->name;
    }
}
```

-----

### 3\. The Sub-Models (e.g., User, UserCertificate)

These models define the "Tier 2" folders and handle the actual file attachments.

```php
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use DotEnvIt\FileManager\Interfaces\FileManagerModelInterface;

class User extends Model implements HasMedia, FileManagerModelInterface
{
    use InteractsWithMedia;

    /**
     * The name displayed on the folder card in Tier 2.
     */
    public function getFileManagerLabel(): string
    {
        return $this->full_name;
    }

    /**
     * Used when creating new records from the File Manager.
     * Points back to the parent ID (e.g., company_id).
     */
    public function getFileManagerForeignKey(): string
    {
        return 'company_id';
    }
}
```

### 4\. UI Component

Insert the component into your Blade template. It will automatically handle the navigation based on your map.

```html
<livewire:file-manager :model="$company" />
```

### Folder Size Formatting

The component automatically handles byte formatting (KB, MB, GB) for folder summaries using internal helpers to ensure type safety in PHP 8.4+.

## ⚙️ Configuration

The `config/file-manager.php` is the central brain of the package. Here is how to configure it:

### 1\. Global Model Definitions (`models`)

This section defines the "Tier 1" folders.

* **`title_column`**: The column used for folder names. Supports dot-notation for relationships (e.g., `user.name`).
* **`flat`**:
    * `false`: **Nested View** (Files grouped inside specific record folders).
    * `true`: **Gallery View** (All files from all records shown in one list).
* **`filters`**: Limit visibility to specific Spatie Media collections or custom JSON properties.

### 2\. Contextual Grouping (`relationships`)

The "Deep Vault" logic. When viewing a parent model (like a **Company**), this closure defines which child models to pull files from when `getFileManagerMap` function is not defined in Parent model.

```php
'relationships' => [
    \App\Models\Company::class => function ($company) {
        return [
            \App\Models\Company::class => [$company->id],
            \App\Models\User::class => [$company->users()->id],
            \App\Models\Certification::class => $company->certifications()->pluck('id'),
        ];
    },
],
```

### 3\. Dynamic Forms (`forms`)

Define upload forms without writing a single line of HTML.

* **`fields`**: Generates a modal form with `text`, `date`, `select`, or `textarea`.
* **`custom_property`**: Data is saved directly into the Spatie `media` table's `custom_properties` column instead of your model's table.

-----

## 🌍 Localization (Languages)

The package is fully translatable. You can customize every piece of text shown in the [File Manager](https://www.google.com/search?q=https://courtile.test/modules/matters/01kptcqwdb34qw6cmba18sqt66/show%3Fview%3Dfile-manager) UI.

### 1\. Publishing Translations

To modify the default English strings or add a new language, publish the language files:

```bash
php artisan vendor:publish --tag="file-manager-translations"
```

This will create `resources/lang/vendor/file-manager/{locale}/messages.php`.

### 2\. Translating Model Names

By default, the UI uses the Class name of your models. You can provide "Human Readable" names in the `models` array of your language file:

```php
// resources/lang/vendor/file-manager/en/messages.php
// model name should be written as snake_case
'models' => [
    'user'             => 'Staff Member',
    'company'          => 'Firm',
    'company_certification'    => 'Diploma',
],
```

*The package automatically looks for the **snake\_case** version of your Model name.*

### 3\. Core UI Strings

You can change standard labels to match your application's terminology:

* **`root_name`**: Changes the first breadcrumb (Default: "File Manager").
* **`record`**: Changes the folder subtext (Default: "1 Record").
* **`new`**: Changes the action button text (Default: "New").

-----

## 📜 License

The MIT License (MIT). Please see License File for more information.

-----

*Built with ❤️ by [Dot-Env-It](https://github.com/dot-env-it)*
