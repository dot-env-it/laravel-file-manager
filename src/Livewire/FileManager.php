<?php

namespace DotEnvIt\FileManager\Livewire;

use DotEnvIt\FileManager\Interfaces\FileManagerModelInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

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
    public $ownerId = null;
    public $ownerType = null;

    // Top of your FileManager.php component
//    protected $queryString = [
//        'view',
//        'selectedType',
//        'selectedId', // Ensure this is a public property
//    ];

    public $isCreating = false;
    public $options = [];
    public $formData = [];
    public $customProperty = [];
    public $remark;
    public $upload; // Temporary storage for the uploaded file

    /**
     * Mount the component with optional context.
     */
    public function mount($ownerId = null, $ownerType = null)
    {
        $this->ownerId = $ownerId;
        $this->ownerType = $ownerType;

        if ($this->ownerId && $this->ownerType) {
            // Start at the 'folder' view so we see the relationship categories
            $this->view = 'folder';
            $this->selectedType = $this->ownerType;
            $this->selectedId = $this->ownerId;
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

            $records = $this->selectedType::where($foreignKey, $this->ownerId)->get();

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
        // 1. Dynamic Validation
        $fields = config("file-manager.forms.{$this->selectedType}")
            ?? config("file-manager.forms.default", []);

        $rules = [
            'upload' => 'required|file|max:10240', // 10MB limit
        ];

        if (isset($fields['fields'])) {
            foreach ($fields['fields'] as $key => $settings) {
                if ($key == 'custom_property') {
                    continue;
                }

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

        $this->validate($rules);

        try {


            // 2. Create Model Instance
            $model = $this->selectedType::find($this->selectedId);

            if (isset($fields['fields'])) {

                foreach ($this->formData as $key => $value) {
                    $model->{$key} = $value;
                }

                // Link to Matter/Owner
                $foreignKey = Str::snake(class_basename($this->ownerType)) . '_id';
                $model->{$foreignKey} = $this->ownerId;
                $model->save();
            }

            $this->customProperty['uploaded_by'] = auth()->id();

            // 3. Attach File via Spatie Media Library
            $collection = Str::camel(class_basename($this->selectedType));
            $model->addMedia($this->upload->getRealPath())
                ->usingFileName($this->upload->getClientOriginalName())
                ->withCustomProperties($this->customProperty)
                ->toMediaCollection($collection);

            // 4. Reset
            $this->reset(['formData', 'customProperty', 'upload', 'remark', 'isCreating']);
            $this->dispatch('notify', message: 'Record created and file attached!');

        } catch (\Exception $e) {
            \Log::error($e->getMessage());

            // 2. Flash to session for the Blade alert
            session()->flash('error', 'Something went wrong!! Please contact to admin.');

            // 3. Keep the notification as well if you like
            $this->dispatch('notify',
                type: 'error',
                message: 'Something went wrong!! Please contact to admin.'
            );
        }
    }

    //
//    public function updatedUpload()
//    {
//        // Optional: Validate immediately when a file is selected
//        $this->validate([
//            'upload' => 'max:10240', // 10MB Limit
//        ]);
//
//
//        $this->saveUpload();
//    }


    public function saveUpload()
    {
        // 1. Validate
        $this->validate([
            'upload' => 'required|max:10240',
        ]);

        // 2. Identify the target (Let's log these to be sure)
        $targetType = $this->selectedType ?? $this->ownerType;
        $targetId = $this->selectedId ?? $this->ownerId;


        if (!$targetType || !$targetId) {
            dd('failed');
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
     * The main data fetcher.
     */
    protected function getItems_v0(): Collection
    {
        $config = config('file-manager.models', []);
        $mediaModelClass = config('media-library.media_model', \Spatie\MediaLibrary\MediaCollections\Models\Media::class);

        // 1. Handle Root Navigation (Global View only)
        if ($this->view === 'root' && !$this->ownerId) {
            return collect($config)
                ->map(fn($settings, $class) => [
                    'name' => $settings['label'] ?? str(class_basename($class))->plural(),
                    'type' => $class,
                    'is_folder' => true,
                    'is_flat' => $settings['flat'] ?? false,
                    'icon' => $settings['icon'] ?? 'bi-folder-fill text-primary',
                ])
                ->when($this->search, function ($items) {
                    return $items->filter(fn($i) => str_contains(strtolower($i['name']), strtolower($this->search)));
                });
        }

        // 2. Handle Folder View (Global Record List)
        if ($this->view === 'folder' && !$this->ownerId) {
            $settings = $config[$this->selectedType] ?? [];
            $titleCol = $settings['title_column'] ?? 'name';
            $searchTerm = strtolower($this->search);

            return $this->selectedType::query()
                ->whereHas('media')
                ->when($this->search, fn($q) => $q->whereRaw("LOWER({$titleCol}) LIKE ?", ["%{$searchTerm}%"]))
                ->get()
                ->map(fn($record) => [
                    'id' => $record->id,
                    'name' => $record->{$titleCol},
                    'type' => $this->selectedType,
                    'is_folder' => true,
                    'icon' => 'bi-folder-fill text-warning',
                ]);
        }

        // 3. Handle Items View (The Actual Files)
        $mediaQuery = $mediaModelClass::query();

        if ($this->ownerId && $this->ownerType) {
            // --- CONTEXTUAL RESOLUTION HIERARCHY ---
            $owner = $this->ownerType::findOrFail($this->ownerId);
            $map = [];

            // A. Check Config Closure
            $configGrouping = config("file-manager.relationships.{$this->ownerType}");
            if (is_callable($configGrouping)) {
                $map = $configGrouping($owner);
            } // B. Check Model Method
            elseif (method_exists($owner, 'getFileManagerMap')) {
                $map = $owner->getFileManagerMap();
            } // C. Fallback: Single Record
            else {
                $map = [$this->ownerType => [$this->ownerId]];
            }

            $mediaQuery->where(function ($q) use ($map) {
                foreach ($map as $modelClass => $ids) {
                    if (empty($ids)) continue;
                    $idArray = $ids instanceof Collection ? $ids->toArray() : (array)$ids;
                    $morphClass = (new $modelClass)->getMorphClass();

                    $q->orWhere(function ($sub) use ($morphClass, $idArray) {
                        $sub->where('model_type', $morphClass)->whereIn('model_id', $idArray);
                    });
                }
            });
        } else {
            // Global Item View for a single selected record
            $modelInstance = new $this->selectedType;
            $mediaQuery->where('model_type', $modelInstance->getMorphClass())
                ->where('model_id', $this->selectedId);
        }

        // Apply Collection Filters (Case Insensitive Index Friendly)
        $settings = $config[$this->selectedType] ?? [];
        if (!empty($settings['filters']['collections'])) {
            $existingInDb = $mediaModelClass::distinct()->pluck('collection_name');
            $configColls = array_map('strtolower', (array)$settings['filters']['collections']);
            $matched = $existingInDb->filter(fn($name) => in_array(strtolower($name), $configColls));
            $mediaQuery->whereIn('collection_name', $matched->toArray());
        }

        // Apply Case-Insensitive Search
        $mediaQuery->when($this->search, function ($q) {
            $term = strtolower($this->search);
            $q->where(function ($sub) use ($term) {
                $sub->whereRaw('LOWER(file_name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(name) LIKE ?', ["%{$term}%"]);
            });
        });

        return $mediaQuery->get()->map(fn($m) => $this->mapMediaItem($m));
    }

    protected function getItems_v1(): \Illuminate\Support\Collection
    {
        $mediaModelClass = config('media-library.media_model', \Spatie\MediaLibrary\MediaCollections\Models\Media::class);
        $mediaQuery = $mediaModelClass::query();

        if ($this->ownerId && $this->ownerType) {
            $owner = $this->ownerType::findOrFail($this->ownerId);
            $map = [];

            // 1. Resolve Map
            $configRelationships = config("file-manager.relationships.{$this->ownerType}");
            if (is_callable($configRelationships)) {
                $map = $configRelationships($owner);
            } elseif (method_exists($owner, 'getFileManagerMap')) {
                $map = $owner->getFileManagerMap();
            } else {
                $map = [$this->ownerType => [$this->ownerId]];
            }

            // 2. Build Query with MorphMap awareness
            $mediaQuery->where(function ($q) use ($map) {
                foreach ($map as $modelClass => $ids) {
                    // Ensure $ids is an array and not empty
                    $idArray = collect($ids)->filter()->toArray();
                    if (empty($idArray)) continue;

                    // CRITICAL: Get the actual string stored in the DB (Handles MorphMaps)
                    $modelInstance = new $modelClass;
                    $morphAlias = method_exists($modelInstance, 'getMorphClass')
                        ? $modelInstance->getMorphClass()
                        : $modelClass;

                    $q->orWhere(function ($sub) use ($morphAlias, $idArray) {
                        $sub->where('model_type', $morphAlias)
                            ->whereIn('model_id', $idArray);
                    });
                }
            });
        } else {
            // Global logic...
        }

        // Apply Search (Case Insensitive)
        if ($this->search) {
            $term = strtolower($this->search);
            $mediaQuery->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(file_name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(name) LIKE ?', ["%{$term}%"]);
            });
        }

        return $mediaQuery->get()->map(fn($m) => $this->mapMediaItem($m));
    }

    protected function getItems(): \Illuminate\Support\Collection
    {
        // 1. Basic Safety & Setup
        if (!$this->ownerId || !$this->ownerType) {
            return collect();
        }

        $mediaModelClass = config('media-library.media_model', \Spatie\MediaLibrary\MediaCollections\Models\Media::class);
        $owner = $this->ownerType::findOrFail($this->ownerId);

        // 2. Resolve the Relationship Map for this specific Matter
        $relConfig = config("file-manager.relationships.{$this->ownerType}");
        $map = is_callable($relConfig)
            ? $relConfig($owner)
            : (method_exists($owner, 'getFileManagerMap') ? $owner->getFileManagerMap() : []);

        // --- VIEW: FOLDER (Categories like "Tasks", "Orders") ---
        if ($this->view === 'folder') {
            return collect($map)->map(function ($ids, $class) use ($mediaModelClass) {
                // Convert to array and filter out nulls/empty strings
                $idArray = collect($ids)->filter()->toArray();

                if (empty($idArray)) return null;

                // Resolve Morph Alias (Handles 'App\Models\Order' vs 'order')
                $modelInstance = new $class;
                $morphAlias = $modelInstance->getMorphClass();

                // PERFORMANCE CHECK: Only show folder if media exists for these specific IDs
                $totalFiles = $mediaModelClass::where('model_type', $morphAlias)
                    ->whereIn('model_id', $idArray)
                    ->count();

//                if (!$totalFiles) return null;

                // 1. Define the translation key
                $transKey = "file-manager::messages.models." . str(class_basename($class))->snake();

                // 2. Check if the translation exists, otherwise use a formatted fallback
                $displayName = Lang::has($transKey)
                    ? __($transKey)
                    : str(class_basename($class))->headline()->plural();

                // This tries to find a translation for the model name, e.g., 'file-manager::models.task'
                return [
                    'name' => $displayName,
                    'type' => $class,
                    'count' => $totalFiles,
                    'is_folder' => true,
                    'icon' => 'bi-folder-fill text-primary',
                ];
            })->filter()->values(); // filter() removes the nulls, values() resets the keys
        }

        // --- VIEW: ITEMS (Files inside a selected category) ---
        if ($this->view === 'items' && $this->selectedType) {
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
                'url' => $m->getUrl(),
                'extension' => $m->extension,
                'icon' => $this->getFileIcon($m->extension),
            ]);
        }

        return collect();
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
            'url' => $m->getUrl(),
            'extension' => $m->extension,
            'is_folder' => false,
        ];
    }

    /**
     * Navigation Logic.
     */
    public function navigate($view, $type = null, $id = null)
    {
        $this->view = $view;
        $this->selectedType = $type;
        $this->selectedId = $id; // Not strictly needed for contextual, but keep for global
        $this->search = '';
    }

    /**
     * Breadcrumb Generator.
     */
    protected function getBreadcrumbs(): array
    {
        $breadcrumbs = [
            [
                'name' => __('file-manager::messages.root_name'),
                'view' => 'folder',
                'active' => $this->view === 'folder', // Active if we are in folder view
            ],
        ];

        if ($this->view === 'items') {
            // 1. Define the translation key
            $transKey = "file-manager::messages.models." . str(class_basename($this->selectedType))->snake();

            // 2. Check if the translation exists, otherwise use a formatted fallback
            $displayName = Lang::has($transKey)
                ? __($transKey)
                : str(class_basename($this->selectedType))->headline()->plural();

            $breadcrumbs[] = [
                'name' => $displayName,
                'view' => 'items',
                'type' => $this->selectedType,
                'active' => true, // The last item in the trail is always active
            ];
            // If we are in items view, the root is no longer the active highlight
            $breadcrumbs[0]['active'] = false;
        }

        return $breadcrumbs;
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
}
