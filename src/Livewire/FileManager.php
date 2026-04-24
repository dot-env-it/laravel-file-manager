<?php

namespace DotEnvIt\FileManager\Livewire;

use DotEnvIt\FileManager\Interfaces\FileManagerModelInterface;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
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
        }
        $this->view = 'folder';
        $this->selectedType = null;// $this->ownerType;
        $this->selectedId = null;//$this->ownerId;
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
                $foreignKey = Str::snake(class_basename($this->modelType)) . '_id';
                $model->{$foreignKey} = $this->modelId;
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

        // FIX: If going back to root folder, ensure we show the Matter's categories
        if ($view === 'folder' && empty($type)) {
            // Option A: If you want root to always be the Category list
            $this->selectedType = null;
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
        // 1. Basic Safety & Setup
//        if (!$this->ownerId || !$this->ownerType) {
//            return collect();
//        }

        $mediaModelClass = config('media-library.media_model', Media::class);
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
                'url' => $m->getUrl(),
                'extension' => $m->extension,
                'icon' => $this->getFileIcon($m->extension),
            ]);
        }

        return collect();
    }

    protected function getBreadcrumbs(): array
    {
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
//            $breadcrumbs[count($breadcrumbs)-2]['active'] = false;
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
            'url' => $m->getUrl(),
            'extension' => $m->extension,
            'is_folder' => false,
        ];
    }
}
