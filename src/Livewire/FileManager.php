<?php

declare(strict_types=1);

namespace DotEnvIt\FileManager\Livewire;

use DotEnvIt\FileManager\Interfaces\FileManagerModelInterface;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

// Fixed: Added Log facade

final class FileManager extends Component
{
    use WithFileUploads;

    // View Constants
    public const VIEW_ROOT             = 'root';
    public const VIEW_FOLDER           = 'folder';
    public const VIEW_ITEMS            = 'items';
    public const VIEW_MODEL_GROUP      = 'model_group';
    public const VIEW_COLLECTION_GROUP = 'collection_group';

    // State properties
    public $view = self::VIEW_ROOT;

    public $selectedType = null;

    public $selectedId = null;

    public $search = '';

    // Contextual properties (e.g. Matter)
    public $modelId = null;

    public $modelType = null;

    // Form/Upload properties
    public $isCreating = false;

    public $options = [];

    public $formData = [];

    public $customProperty = [];

    public $upload;

    public function mount($model = null)
    {
        if ($model) {
            $this->modelId   = $model->id;
            $this->modelType = get_class($model);
            $this->view      = self::VIEW_FOLDER;
        } else {
            $this->view         = self::VIEW_ITEMS;
            $this->selectedType = 'all';
        }
    }

    protected function getItems(): Collection
    {
        return $this->modelId ? $this->getModelItems() : $this->getGlobalItems();
    }

    protected function getModelItems(): Collection
    {
        $owner     = $this->modelType::findOrFail($this->modelId);
        $relConfig = config("file-manager.relationships.{$this->modelType}");
        $map       = is_callable($relConfig) ? $relConfig($owner) : ($owner->getFileManagerMap() ?? []);

        // TIER 1: Relationship Categories (Folders)
        if ($this->view === self::VIEW_FOLDER && ! $this->selectedType) {
            return collect($map)->map(function ($ids, $class) {
                $idArray = collect($ids)->filter()->toArray();
                if (empty($idArray)) {
                    return null;
                }

                return [
                    'name'      => $this->getTranslationForModel($class),
                    'type'      => $class,
                    'count'     => $this->getMediaModel()::where('model_type', (new $class)->getMorphClass())
                        ->whereIn('model_id', $idArray)->count(),
                    'is_folder' => true,
                    'is_flat'   => config("file-manager.models.{$class}.flat", false),
                    'view'      => self::VIEW_ITEMS,
                    'icon'      => 'bi-folder-fill text-primary',
                ];
            })
                ->filter()
                ->when($this->search, function ($collection) {
                    return $collection->filter(
                        fn ($item) => Str::contains($item['name'], $this->search, ignoreCase: true)
                    );
                })
                ->sortByDesc('count')->values();
        }

        // TIER 2: Drill-down to Records or Files
        if ($this->selectedType) {
            $settings   = config("file-manager.models.{$this->selectedType}", []);
            $isFlat     = $settings['flat'] ?? false;
            $allowedIds = collect($map[$this->selectedType] ?? [])->filter()->toArray();

            // If it's a nested model and no specific ID is chosen, show Records as folders
            if (! $isFlat && ! $this->selectedId) {
                return $this->selectedType::whereIn('id', $allowedIds)
                    ->when(
                        $this->search,
                        fn ($q) => $q->where(
                            $this->getTitleColumnName($this->selectedType),
                            'ilike',
                            "%{$this->search}%"
                        )
                    )
                    ->get()
                    ->map(fn ($record) => [
                        'id'        => $record->id,
                        'name'      => $record->getFileManagerLabel(),
                        'type'      => $this->selectedType,
                        'is_folder' => true,
                        'view'      => self::VIEW_ITEMS,
                        'count'     => method_exists($record, 'media') ? $record->media()->count() : 0,
                        'icon'      => 'bi-folder-fill text-warning',
                    ]);
            }

            // Show Files
            return $this->getMediaModel()::where('model_type', (new $this->selectedType)->getMorphClass())
                ->whereIn('model_id', $this->selectedId ? [$this->selectedId] : $allowedIds)
                ->when($this->search, fn ($q) => $q->where('file_name', 'ilike', "%{$this->search}%"))
                ->get()
                ->map(fn ($m) => $this->formatMediaItem($m));
        }

        return collect();
    }

    protected function getGlobalItems(): Collection
    {
        $media = $this->getMediaModel();

        if ($this->view === self::VIEW_ITEMS && $this->selectedType === 'all') {
            return $media::select('model_type')->distinct()->get()->map(function ($m) {
                $count = $this->getMediaModel()::where('model_type', $m->model_type)->count();

                return [
                    'name'      => $this->getTranslationForModel($m->model_type),
                    'is_folder' => true,
                    'view'      => self::VIEW_MODEL_GROUP,
                    'type'      => $m->model_type,
                    'count'     => $count,
                    'icon'      => 'bi-folder-fill text-primary',
                ];
            })->when($this->search, function ($collection) {
                return $collection->filter(
                    fn ($item) => str_contains(strtolower($item['name']), strtolower($this->search))
                );
            });
        }

        if ($this->view === self::VIEW_MODEL_GROUP) {
            return $media::where('model_type', $this->selectedType)
                ->when($this->search, fn ($q) => $q->where('file_name', 'like', "%{$this->search}%"))
                ->get()
                ->map(fn ($m) => $this->formatMediaItem($m));
        }

        return collect();
    }

    public function storeRecordWithFile()
    {
        $isFlat = config("file-manager.models.{$this->selectedType}.flat", false);

        $this->validate([
            'upload'     => 'required|file|max:' . (config('file-manager.max_file_size', 10240)),
            'selectedId' => (! $isFlat && $this->options) ? 'required' : 'nullable',
        ]);

        try {
            if ($isFlat) {
                $targetModel              = new $this->selectedType;
                $foreignKey               = $targetModel->getFileManagerForeignKey();
                $targetModel->$foreignKey = $this->modelId;
                foreach ($this->formData as $key => $val) {
                    $targetModel->$key = $val;
                }
                $targetModel->save();
            } else {
                $targetModel = $this->selectedType::findOrFail($this->selectedId);
            }

            $targetModel->addMedia($this->upload->getRealPath())
                ->usingFileName($this->upload->getClientOriginalName())
                ->withCustomProperties($this->customProperty)
                ->toMediaCollection(config("file-manager.models.{$this->selectedType}.collection", 'documents'));

            $this->isCreating = false;
            $this->reset(['formData', 'customProperty', 'upload']);
            session()->flash('success', 'File uploaded successfully.');
        } catch (Exception $e) {
            Log::error('File Manager Upload Error: ' . $e->getMessage());
            session()->flash('error', 'Could not save: ' . $e->getMessage());
        }
    }

    public function startCreation()
    {
        $this->isCreating = true;
        $this->reset(['formData', 'customProperty', 'upload', 'options']);
        $isFlat = config("file-manager.models.{$this->selectedType}.flat", false);

        if (! $isFlat && class_exists($this->selectedType)) {
            $modelInstance = new $this->selectedType;
            if ($modelInstance instanceof FileManagerModelInterface && $this->modelId) {
                $foreignKey = $modelInstance->getFileManagerForeignKey();
                $records    = $this->selectedType::where($foreignKey, $this->modelId)->get();
                $this->options
                            =
                    $records->map(fn ($item) => ['id' => $item->id, 'label' => $item->getFileManagerLabel()])->toArray(
                    );
                if (count($this->options) === 1) {
                    $this->selectedId = $this->options[0]['id'] ?? null;
                }
            }
        }
    }

    public function navigate($view, $type = '', $id = '')
    {
        $this->view         = $view;
        $this->selectedType = $type;
        $this->selectedId   = $id;
        $this->isCreating   = false;
        $this->reset('search');
    }

    protected function formatMediaItem($m): array
    {
        return [
            'id'        => $m->id, 'name' => $m->file_name, 'is_folder' => false,
            'size'      => $m->human_readable_size, 'url' => $this->getFileUrl($m),
            'extension' => $m->extension, 'icon' => $this->getFileIcon($m->extension),
            'custom'    => collect($m->custom_properties)
                ->only(config('file-manager.visible_custom_properties', []))
                ->toArray(),
        ];
    }

    protected function getFileUrl($media)
    {
        $disk = config('media-library.disk_name');
        if (in_array(config("filesystems.disks.{$disk}.driver"), ['s3', 'r2', 'gcs'])) {
            return $media->getTemporaryUrl(now()->addMinutes(20));
        }
        $route = config('file-manager.download_route', 'file-manager.download');

        return ($disk !== 'public' && Route::has($route)) ? route($route, ['media' => $media->uuid]) : $media->getUrl();
    }

    public function getBreadcrumbs(): array
    {
        $size = $this->formatBytes($this->getCurrentStateSize());

        // Check if we are at the very beginning of the navigation
        $isAtRoot = $this->modelId
            ? ($this->view === self::VIEW_FOLDER && ! $this->selectedType)
            : ($this->view === self::VIEW_ITEMS && $this->selectedType === 'all');

        $crumbs = [
            [
                'name'   => __('file-manager::messages.root_name') . ($isAtRoot ? " ($size)" : ''),
                'view'   => $this->modelId ? self::VIEW_FOLDER : self::VIEW_ITEMS,
                'type'   => $this->modelId ? null : 'all',
                'id'     => null, // Key must exist to avoid PHP 8.4 errors
                'active' => $isAtRoot,
            ],
        ];

        if ($this->selectedType && $this->selectedType !== 'all') {
            $crumbs[] = [
                'name'   => $this->getTranslationForModel($this->selectedType) . (! $this->selectedId ? " ($size)"
                        : ''),
                'view'   => $this->modelId ? self::VIEW_ITEMS : self::VIEW_MODEL_GROUP,
                'type'   => $this->selectedType,
                'id'     => null, // Ensure consistency
                'active' => ! $this->selectedId,
            ];
        }

        if ($this->selectedId) {
            $name = $this->modelId
                ? ($this->selectedType::find($this->selectedId)?->getFileManagerLabel() ?? 'Details')
                : str($this->selectedId)->headline();

            $crumbs[] = [
                'name'   => $name . " ($size)",
                'view'   => $this->view,
                'type'   => $this->selectedType,
                'id'     => $this->selectedId,
                'active' => true,
            ];
        }

        return $crumbs;
    }

    protected function getCurrentStateSize()
    {
        $media = $this->getMediaModel();

        // If we are looking at a specific folder/type (e.g., Petitioner)
        if ($this->selectedType && $this->selectedType !== 'all') {
            $owner     = $this->modelType::find($this->modelId);
            $relConfig = config("file-manager.relationships.{$this->modelType}");
            $map       = is_callable($relConfig) ? $relConfig($owner) : ($owner->getFileManagerMap() ?? []);

            $allowedIds = collect($map[$this->selectedType] ?? [])->filter()->toArray();

            // If we have a specific record (e.g., TVS Credit Services Ltd)
            if ($this->selectedId) {
                return $media::where('model_type', (new $this->selectedType)->getMorphClass())
                    ->where('model_id', $this->selectedId)
                    ->sum('size');
            }

            // Inside getModelItems() -> Tier 2 logic
            return $media::where('model_type', (new $this->selectedType)->getMorphClass())
                ->whereIn('model_id', $allowedIds)
                ->sum('size');
        }

        // Default: Total size of everything in this Matter
        if ($this->modelId) {
            $owner     = $this->modelType::find($this->modelId);
            $relConfig = config("file-manager.relationships.{$this->modelType}");
            $map       = is_callable($relConfig) ? $relConfig($owner) : ($owner->getFileManagerMap() ?? []);

            $total = 0;
            foreach ($map as $class => $ids) {
                $total += $media::where('model_type', (new $class)->getMorphClass())
                    ->whereIn('model_id', collect($ids)->toArray())
                    ->sum('size');
            }

            return $total;
        }

        return $media::sum('size');
    }

    private function formatBytes($bytes, $precision = 2)
    {
        // Cast to float to handle strings returned by DB queries
        $bytes = (float) $bytes;

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);

        // The error happens here because log() was receiving a string
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    protected function getFileIcon($ext): string
    {
        return match (strtolower($ext)) {
            'pdf'                => 'bi-file-pdf text-danger',
            'doc', 'docx'        => 'bi-file-word text-primary',
            'xls', 'xlsx'        => 'bi-file-excel text-success',
            'png', 'jpg', 'jpeg' => 'bi-file-image text-warning',
            default              => 'bi-file-earmark-text text-gray-400',
        };
    }

    protected function getTranslationForModel($class): string
    {
        $key = 'file-manager::messages.models.' . Str::snake(class_basename($class));

        return Lang::has($key) ? __($key) : Str::headline(class_basename($class));
    }

    protected function getMediaModel()
    {
        return config('media-library.media_model');
    }

    protected function getTitleColumnName($modelType)
    {
        return config("file-manager.models.{$modelType}.title_column");
    }

    public function render()
    {
        $items = $this->getItems();

        return view(
            'file-manager::livewire.file-manager',
            ['items' => $items, 'totalCount' => $items->count(), 'breadcrumbs' => $this->getBreadcrumbs()]
        );
    }
}
