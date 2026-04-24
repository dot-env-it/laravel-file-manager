<?php

namespace DotEnvIt\FileManager\Livewire;

use DotEnvIt\FileManager\Interfaces\FileManagerModelInterface;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class FileManager extends Component
{
    use WithFileUploads;


    // State properties
    public $view = 'root'; // root, folder, items
    public $selectedType = null;
    public $selectedId = null;
    public $search = '';

    public $relationOptions = [];
    public $targetRecordId = null; // The specific ID (e.g., petitioner_id) we are attaching to


    // Contextual properties (for Matter-specific views)
    public $modelId = null;
    public $modelType = null;

    public $isCreating = false;
    public $options = [];
    public $formData = [];
    public $customProperty = [];
    public $remark;
    public $upload; // Temporary storage for the uploaded file

    /**
     * Mount the component with optional context.
     */
    public function mount($model = null)
    {

        if ($model) {
            $this->modelId = $model->id;
            $this->modelType = get_class($model);

            if ($this->modelId && $this->modelType) {
                // Start at the 'folder' view so we see the relationship categories
            }
            $this->view = 'folder';
            $this->selectedType = null;// $this->ownerType;
            $this->selectedId = null;//$this->ownerId;
        } else {
            $this->view = 'items';
            $this->selectedType = 'all';
            $this->selectedId = null;
        }
    }

    /**
     * Prepare the dynamic form keys to avoid "Property not found" errors
     */
    public function startCreation()
    {
        $this->isCreating = true;
        $this->formData = [];
        $this->upload = null;
        $this->remark = '';

        $fields = config("file-manager.forms.{$this->selectedType}")
            ?? config("file-manager.forms.default");

        if (isset($fields['fields'])) {
            foreach ($fields['fields'] as $key => $settings) {
                if ($key == 'custom_property') {
                    continue;
                }

                $this->formData[$key] = ''; // Initialize key
            }
        }

        if (isset($fields['custom_property'])) {
            foreach ($fields['custom_property'] as $key => $settings) {
                $this->customProperty[$key] = ''; // Initialize key
            }
        }

        $this->selectedIds = [];

        $modelInstance = new $this->selectedType;
        $foreignKey = null;

        // 1. Create a blank instance to check for the method
        $modelInstance = new $this->selectedType;

        // Safety check: Does the model implement our package interface?
        if ($modelInstance instanceof FileManagerModelInterface) {
            $foreignKey = $modelInstance->getFileManagerForeignKey();

            $records = $this->selectedType::where($foreignKey, $this->modelId)->get();

            $this->options = $records->map(fn($item) => [
                'id' => $item->id,
                'label' => $item->getFileManagerLabel(), // Call the interface method
            ])->toArray();
        } else {
            // Fallback for models not using the interface
            $this->dispatch('notify', type: 'error', message: 'Model must implement FileManagerModelInterface');
            return;
        }

        if (count($this->options) === 1) {
            $this->selectedIds = [$this->options[0]['id']];
        }
    }

    public function storeRecordWithFile()
    {
        // Check if we are attaching directly to the parent model
        $isParentModel = ($this->selectedType === $this->modelType);

        // 1. Dynamic Validation
        $fields = config("file-manager.forms.{$this->selectedType}")
            ?? config("file-manager.forms.default", []);

        $rules = [
            'upload' => 'required|file|max:10240', // 10MB limit
        ];

        if (isset($fields['fields'])) {
            foreach ($fields['fields'] as $key => $settings) {
                // You can pull 'required' or other rules directly from config if you want
                $rules["formData.{$key}"] = $settings['rules'] ?? 'required';
            }
        }

        if (isset($fields['custom_property'])) {
            foreach ($fields['custom_property'] as $key => $settings) {
                // You can pull 'required' or other rules directly from config if you want
                $rules["customProperty.{$key}"] = $settings['rules'] ?? 'nullable';
            }
        }
        // Only require selectedId if it's NOT a flat category AND NOT the parent model
        $settings = config("file-manager.models.{$this->selectedType}", []);
        $isFlat = $settings['flat'] ?? false;

        if (!$isFlat && !$isParentModel) {
            $rules['selectedId'] = 'required';
        }

        $this->validate($rules);

        try {

            if ($isParentModel) {
                // Attach directly to the Matter record we are currently viewing
                $model = $this->modelType::findOrFail($this->modelId);
            } elseif ($isFlat) {
                $model = new $this->selectedType;
                // Link to parent
                $foreignKey = $model->getFileManagerForeignKey();
                $model->{$foreignKey} = $this->modelId;
            } else {
                $model = $this->selectedType::findOrFail($this->selectedId);
            }

            if (isset($fields['fields'])) {

                foreach ($this->formData as $key => $value) {
                    $model->{$key} = $value;
                }

                $model->save();
            }

            $this->customProperty['uploaded_by'] = auth()->id();

            // 3. Attach File via Spatie Media Library
            $collection = Str::camel(class_basename($model->getFileManagerLabel() ?? $this->selectedType));

            $model->addMedia($this->upload->getRealPath())
                ->usingFileName($this->upload->getClientOriginalName())
                ->withCustomProperties($this->customProperty)
                ->toMediaCollection($collection);

            // 4. Reset
            $this->reset(['formData', 'customProperty', 'upload', 'remark', 'isCreating']);
            $this->dispatch('notify', message: 'Record created and file attached!');

        } catch (Exception $e) {
            Log::error($e->getMessage());

            // 2. Flash to session for the Blade alert
            session()->flash('error', 'Something went wrong!! Please contact to admin.');

            // 3. Keep the notification as well if you like
            $this->dispatch('notify',
                type: 'error',
                message: 'Something went wrong!! Please contact to admin.'
            );
        }
    }

    public function saveUpload()
    {
        // 1. Validate
        $this->validate([
            'upload' => 'required|max:10240',
        ]);

        // 2. Identify the target (Let's log these to be sure)
        $targetType = $this->selectedType ?? $this->modelType;
        $targetId = $this->selectedId ?? $this->modelId;


        if (!$targetType || !$targetId) {
            session()->flash('error', 'Target model or ID missing.');
            return;
        }

        $model = $targetType::find($targetId);

        if (!$model) {
            session()->flash('error', 'Target record not found in database.');
            return;
        }

        // 3. Collection Name
        $collectionName = str(class_basename($targetType))->camel()->toString();

        // 4. Attach
        $model->addMedia($this->upload->getRealPath())
            ->usingFileName($this->upload->getClientOriginalName())
            ->toMediaCollection($collectionName);

        // 5. Reset and Refresh
        $this->upload = null;

        // IMPORTANT: If you are using a computed property for $items,
        // you might need to force a refresh or unset a cached variable.
        // $this->dispatch('file-uploaded');
    }

    /**
     * Navigation Logic.
     */
    // FileManager.php

    public function navigate($view, $type = '', $id = '')
    {
        $this->view = $view;
        $this->selectedType = $type;
        $this->selectedId = $id;
        $this->isCreating = false;
        $this->options = [];

        // FIX: If going back to root folder, ensure we show the Matter's categories
        if ($view === 'folder' && empty($type)) {
            // Option A: If you want root to always be the Category list
            $this->selectedType = null;
        }

        // If we go back to root, we are effectively looking for all models
        if ($view === 'folder' && !$this->modelId) {
            $this->view = 'items';
            $this->selectedType = 'all';
        }

        // If we are at the root and moving forward
        if ($view === 'model_group' || $view === 'collection_group') {
            $this->modelId = null; // Ensure we aren't filtered by a specific Matter
        }

        $this->reset('search');
    }

    /**
     * Render the component.
     */
    public function render()
    {
        $items = $this->getItems();

        return view('file-manager::livewire.file-manager', [
            'items' => $items,
            'totalCount' => $items->count(),
            'breadcrumbs' => $this->getBreadcrumbs(),
        ]);
    }

    protected function getItems(): Collection
    {
        if (!$this->modelId || !$this->modelType) {
            return $this->getGlobalItems();
        }

        $mediaModelClass = config('media-library.media_model');
        $owner = $this->modelType::findOrFail($this->modelId);

        // 2. Resolve the Relationship Map for this specific Matter
        $relConfig = config("file-manager.relationships.{$this->modelType}");
        $map = is_callable($relConfig)
            ? $relConfig($owner)
            : (method_exists($owner, 'getFileManagerMap') ? $owner->getFileManagerMap() : []);

        // --- VIEW: FOLDER (Categories like "Tasks", "Orders") ---
        if ($this->view === 'folder' && !$this->selectedType) {
            return collect($map)->map(function ($ids, $class) use ($mediaModelClass) {
                $idArray = collect($ids)->filter()->toArray();
                if (empty($idArray)) return null;

                $modelInstance = new $class;
                $morphAlias = $modelInstance->getMorphClass();
                $totalFiles = $mediaModelClass::where('model_type', $morphAlias)
                    ->whereIn('model_id', $idArray)
                    ->count();

                $transKey = "file-manager::messages.models." . str(class_basename($class))->snake();
                $displayName = Lang::has($transKey) ? __($transKey) : str(class_basename($class))->headline()->plural();

                return [
                    'name' => $displayName,
                    'type' => $class,
                    'count' => $totalFiles,
                    'is_folder' => true,
                    'icon' => 'bi-folder-fill text-primary',
                ];
            })
                ->filter() // First, remove nulls from empty relationships
                ->filter(function ($folder) {
                    if (empty($this->search)) return true;
                    // The error happens if $folder is null here, but ->filter() above prevents that
                    return str_contains(strtolower($folder['name']), strtolower($this->search));
                })
                ->sortByDesc('count')
                ->values();
        }

        // --- VIEW: ITEMS (Files inside a selected category) ---
        if ($this->view === 'items' && $this->selectedType) {
            $config = config('file-manager.models', []);
            $settings = $config[$this->selectedType] ?? [];
            $isFlat = $settings['flat'] ?? false;
            $allowedIds = collect($map[$this->selectedType] ?? [])->filter()->toArray();

            // If NOT flat and we haven't selected a specific record ID yet,
            // show the list of Records (Petitioners) as folders.
            if (!$isFlat && !$this->selectedId) {
                $query = $this->selectedType::whereIn('id', $allowedIds);

                // Only filter by media if the relationship exists to prevent Internal Server Error
                if (method_exists($this->selectedType, 'media')) {
                    $query->whereHas('media');
                }

                if ($this->search) {
                    $term = strtolower($this->search);

                    // If the model has a custom search method, use it
                    if (method_exists($this->selectedType, 'search')) {
                        $query = $this->selectedType::search($query, $term);
                    } else {
                        // Fallback to basic column search from config
                        $titleCol = $settings['title_column'] ?? 'name';
                        $query->whereRaw("LOWER({$titleCol}) LIKE ?", ["%{$term}%"]);
                    }
                }

                return $query->get()->map(fn($record) => [
                    'id' => $record->id,
                    'name' => $record->getFileManagerLabel(),
                    'type' => $this->selectedType,
                    'is_folder' => true,
                    'count' => method_exists($record, 'media') ? $record->media()->count() : 0,
                    'icon' => 'bi-folder-fill text-warning',
                ]);
            }

            $mediaQuery = $mediaModelClass::query();

            $modelInstance = new $this->selectedType;
            $morphAlias = $modelInstance->getMorphClass();

            // Get only the IDs that belong to the current Matter for this specific relationship
            $allowedIds = collect($map[$this->selectedType] ?? [])->toArray();

            $mediaQuery->where('model_type', $morphAlias)
                ->whereIn('model_id', $allowedIds);

            // Apply Search
            if ($this->search) {
                $term = strtolower($this->search);
                $mediaQuery->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(file_name) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(name) LIKE ?', ["%{$term}%"]);
                });
            }

            return $mediaQuery->get()->map(fn($m) => [
                'id' => $m->id,
                'name' => $m->file_name,
                'count' => 0,
                'is_folder' => false,
                'size' => $m->human_readable_size,
                'size_bytes' => $m->size,
                'url' => $this->getFileUrl($m),
                'custom' => collect($m->custom_properties)
                    ->only(config('file-manager.visible_custom_properties', []))
                    ->toArray(),
                'extension' => $m->extension,
                'icon' => $this->getFileIcon($m->extension),
            ]);
        }

        return collect();
    }

    protected function getBreadcrumbs_v0(): array
    {
        // If no model is passed, we are in Global Mode
        if (!$this->modelType || !$this->modelId) {
            $crumbs = [
                [
                    'name' => __('file-manager::messages.root_name'),
                    'view' => 'items',
                    'type' => 'all',
                    'active' => ($this->view === 'items'),
                ],
            ];

            if ($this->selectedType && $this->selectedType !== 'all') {
                $classBasename = str($this->selectedType)->classBasename()->snake();
                $crumbs[] = [
                    'name' => __("file-manager::messages.models.{$classBasename}") ?: $classBasename->headline(),
                    'view' => 'model_group',
                    'type' => $this->selectedType,
                    'id' => null, // Ensure this exists
                    'active' => ($this->view === 'model_group'),
                ];
            }

            if ($this->view === 'collection_group') {
                $crumbs[] = [
                    'name' => str($this->selectedId)->headline(),
                    'view' => 'collection_group', // Add this
                    'type' => $this->selectedType, // Add this
                    'id' => $this->selectedId,      // Add this
                    'active' => ($this->view === 'collection_group'),
                ];
            }

            return $crumbs;
        }

        $breadcrumbs = [
            [
                'name' => __('file-manager::messages.root_name'),
                'view' => 'folder',
                'type' => null,
                'id' => null,
                'active' => $this->view === 'folder' && !$this->selectedType,
            ],
        ];

        // Added check: Ensure selectedType isn't empty before trying to use it
        if ($this->view === 'items' && !empty($this->selectedType)) {
            $className = class_basename($this->selectedType);
            $transKey = "file-manager::messages.models." . str($className)->snake();

            $displayName = Lang::has($transKey)
                ? __($transKey)
                : str($className)->headline()->plural();

            $breadcrumbs[] = [
                'name' => $displayName,
                'view' => 'items',
                'type' => $this->selectedType,
                'active' => true,
            ];
            $breadcrumbs[0]['active'] = false;
        }

        if ($this->view !== 'root' && $this->selectedId) {
            $record = $this->selectedType::find($this->selectedId);
            $breadcrumbs[] = [
                'name' => $record ? $record->getFileManagerLabel() : 'Record',
                'view' => 'items',
                'type' => $this->selectedType,
                'id' => $this->selectedId,
                'active' => true,
            ];

            // Set previous crumb to inactive
            $breadcrumbs[count($breadcrumbs) - 2]['active'] = false;
        }

        return $breadcrumbs;
    }

    protected function getBreadcrumbs(): array
    {
        $mediaClass = config('media-library.media_model');
        // Calculate total size of current items
        // Calculate size based on current view
        $totalSizeBytes = match ($this->view) {
            // If viewing files in a collection
            'collection_group' => $mediaClass::where('model_type', $this->selectedType)
                ->where('collection_name', $this->selectedId)
                ->sum('size'),

            // If viewing collections in a model group
            'model_group' => $mediaClass::where('model_type', $this->selectedType)
                ->sum('size'),

            // If at the root (Global Library)
            default => $mediaClass::sum('size'),
        };

        $totalSizeFormatted = $this->formatBytes($totalSizeBytes);

        // --- MODE A: Global File Manager ---
        if (!$this->modelId) {
            $crumbs = [
                [
                    'name' => __('file-manager::messages.root_name') . ($this->view === 'items' && $this->selectedType === 'all' ? " ($totalSizeFormatted)" : ""),
                    'view' => 'items',
                    'type' => 'all',
                    'id' => null,
                    'active' => ($this->view === 'items' && $this->selectedType === 'all'),
                ],
            ];

            if ($this->selectedType && $this->selectedType !== 'all') {
                $classBasename = str($this->selectedType)->classBasename()->snake();
                $label = __("file-manager::messages.models.{$classBasename}") ?: $classBasename->headline();

                $crumbs[] = [
                    'name' => $label . ($this->view === 'model_group' ? " ($totalSizeFormatted)" : ""),
                    'view' => 'model_group',
                    'type' => $this->selectedType,
                    'id' => null,
                    'active' => ($this->view === 'model_group'),
                ];
            }

            if ($this->view === 'collection_group') {
                $crumbs[] = [
                    'name' => str($this->selectedId)->headline() . " ($totalSizeFormatted)",
                    'view' => 'collection_group',
                    'type' => $this->selectedType,
                    'id' => $this->selectedId,
                    'active' => true,
                ];
            }

            return $crumbs;
        }
        $breadcrumbs = [
            [
                'name' => __('file-manager::messages.root_name'),
                'view' => 'folder',
                'type' => null,
                'id' => null,
                'active' => $this->view === 'folder' && !$this->selectedType,
            ],
        ];

        // Added check: Ensure selectedType isn't empty before trying to use it
        if ($this->view === 'items' && !empty($this->selectedType)) {
            $className = class_basename($this->selectedType);
            $transKey = "file-manager::messages.models." . str($className)->snake();

            $displayName = Lang::has($transKey)
                ? __($transKey)
                : str($className)->headline()->plural();

            $breadcrumbs[] = [
                'name' => $displayName,
                'view' => 'items',
                'type' => $this->selectedType,
                'active' => true,
            ];
            $breadcrumbs[0]['active'] = false;
        }

        if ($this->view !== 'root' && $this->selectedId) {
            $record = $this->selectedType::find($this->selectedId);
            $breadcrumbs[] = [
                'name' => $record ? $record->getFileManagerLabel() : 'Record',
                'view' => 'items',
                'type' => $this->selectedType,
                'id' => $this->selectedId,
                'active' => true,
            ];

            // Set previous crumb to inactive
            $breadcrumbs[count($breadcrumbs) - 2]['active'] = false;
        }

        return $breadcrumbs;
    }

    /**
     * Helper for Metronic Icons based on extension.
     */
    protected function getFileIcon($extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => 'bi-file-pdf text-danger',
            'doc', 'docx' => 'bi-file-word text-primary',
            'xls', 'xlsx' => 'bi-file-excel text-success',
            'png', 'jpg', 'jpeg', 'svg' => 'bi-file-image text-warning',
            'zip', 'rar' => 'bi-file-zip text-info',
            default => 'bi-file-earmark-text text-gray-400',
        };
    }

    /**
     * Map database record to UI array.
     */
    protected function mapMediaItem($m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->file_name,
            'category' => str($m->collection_name)->headline(),
            'size' => $m->human_readable_size,
            'size_bytes' => $m->size,
            'url' => $this->getFileUrl($m),
            'extension' => $m->extension,
            'custom' => collect($m->custom_properties)
                ->only(config('file-manager.visible_custom_properties', []))
                ->toArray(),
            'is_folder' => false,
        ];
    }

    protected function getGlobalItems(): \Illuminate\Support\Collection
    {
        $mediaClass = config('media-library.media_model');

        // TIER 1: Show Models as Folders
        if ($this->view === 'items' && $this->selectedType === 'all') {
            return $mediaClass::query()
                ->select('model_type')
                ->distinct()
                ->get()
                ->map(function ($m) use ($mediaClass) {
                    $classBasename = str($m->model_type)->classBasename();

                    // 1. Get the localized label
                    $label = __("file-manager::models.{$classBasename}");
                    if ($label === "file-manager::models.{$classBasename}") {
                        $label = $classBasename->headline();
                    }

                    // 2. Check for single collection shortcut
                    $collections = $mediaClass::where('model_type', $m->model_type)
                        ->distinct()
                        ->pluck('collection_name');
                    $collectionCount = $collections->count();

                    if ($collections->count() === 1) {
                        // Shortcut: Click takes user directly to items
                        return [
                            'id' => $collections->first(),
                            'name' => $label,
                            'is_folder' => true,
                            'icon' => 'bi-folder-fill text-primary',
                            'view' => 'collection_group', // Direct to Tier 3
                            'type' => $m->model_type,
                            'extension' => $m->extension,
                            'count' => $collectionCount,
                            //'icon' => 'folder-special', // Optional: unique icon for direct folders
                        ];
                    }

                    // Standard behavior: Click takes user to Tier 2 (Collections)
                    return [
                        'id' => null,
                        'name' => $label,
                        'is_folder' => true,
                        'view' => 'model_group',
                        'type' => $m->model_type,
                        'icon' => 'bi-folder-fill text-primary',
                        'extension' => $m->extension,
                        'count' => $collectionCount,
                    ];
                });
        }

        // Tier 2: Model Selected (Show Collections)
        if ($this->view === 'model_group') {
            return $mediaClass::where('model_type', $this->selectedType)
                ->select('collection_name')
                ->distinct()
                ->get()
                ->map(fn($m) => [
                    'name' => str($m->collection_name)->headline(),
                    'is_folder' => true,
                    'view' => 'collection_group', // Next step
                    'type' => $this->selectedType,
                    'id' => $m->collection_name,
                    'icon' => 'bi-folder-fill text-primary',
                    'extension' => $m->extension,
                    'count' => $mediaClass::where('model_type', $this->selectedType)
                        ->where('collection_name', $m->collection_name)
                        ->count(),
                ]);
        }

        // Tier 3: Collection Selected (Show Files)
        if ($this->view === 'collection_group') {
            return $mediaClass::where('model_type', $this->selectedType)
                ->where('collection_name', $this->selectedId)
                ->get()
                ->map(fn($m) => [
                    'name' => $m->file_name,
                    'is_folder' => false,
                    'url' => $this->getFileUrl($m),
                    'icon' => $this->getFileIcon($m->extension),
                    'extension' => $m->extension,
                    'custom' => collect($m->custom_properties)
                        ->only(config('file-manager.visible_custom_properties', []))
                        ->toArray(),
                    'size' => $m->human_readable_size,
                    'size_bytes' => $m->size,

                ]);
        }

        return collect();
    }

    /**
     * Helper to format bytes to human readable
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    protected function getFileUrl($media)
    {
        // 1. Get Spatie's configured disk
        $disk = config('media-library.disk_name');
        $driver = config("filesystems.disks.{$disk}.driver");

        // 2. Handle Cloud Disks (S3, R2, etc.)
        if (in_array($driver, ['s3', 'r2', 'gcs'])) {
            return $media->getTemporaryUrl(now()->addMinutes(20));
        }

        // 3. Handle Private/Local Disks via Configured Route
        $routeName = config('file-manager.download_route', 'file-manager.download');

        if ($disk !== 'public' && Route::has($routeName)) {
            return route($routeName, ['media' => $media]);
        }

        // 4. Default Fallback
        return $media->getUrl();
    }
}
