<div class="card card-flush shadow-sm">
    <div class="card-header pt-8">
        <div class="card-title">
            <div class="d-flex align-items-center position-relative my-1 me-2"
                 x-data="{
        tempSearch: @entangle('search').live,
        actualInput: '',
        handleInput(e) {
            this.actualInput = e.target.value;
            // Sync with Livewire only at 0 or 3+ characters
            if (this.actualInput.length >= 3 || this.actualInput.length === 0) {
                this.tempSearch = this.actualInput;
            }
        }
     }">

                <i class="bi bi-search position-absolute ms-6"></i>

                <input type="text"
                       class="form-control form-control-solid w-250px ps-15"
                       placeholder="Search..."
                       x-on:input="handleInput"
                       :value="actualInput"/>

                <template x-if="actualInput.length > 0 && actualInput.length < 3">
        <span class="position-absolute end-0 me-4 badge badge-light-primary fs-9 animate__animated animate__fadeIn">
            Type <span x-text="3 - actualInput.length"></span> more...
        </span>
                </template>

                <div wire:loading wire:target="search"
                     class="spinner-border spinner-border-sm text-primary position-absolute end-0 me-3"></div>
            </div>
        </div>

        <div class="card-toolbar">
            @if($view === 'items' && !$isCreating && array_key_exists($selectedType, config('file-manager.forms', [])))
                <div class="d-flex align-items-center position-relative my-1">
                    <button wire:click="startCreation" class="btn btn-primary btn-sm">
                        <i class="fas fa-upload"></i>
                        {{ __('file-manager::messages.new') }} {{ __('file-manager::messages.models.' . str(class_basename($selectedType))->snake()->lower()) }}
                    </button>
                </div>
            @endif
        </div>
    </div>

    <div class="card-body">
        <div class="d-flex flex-stack">
            <div class="badge badge-lg badge-light-primary">
                <div class="d-flex align-items-center flex-wrap mb-1">
                    @foreach($breadcrumbs as $crumb)
                        <div class="d-flex align-items-center">
                            <a href="javascript:;"
                               wire:click="navigate('{{ $crumb['view'] }}', '{{ isset($crumb['type']) ? addslashes($crumb['type']) : '' }}', '{{ $crumb['id'] ?? '' }}')"
                               class="text-hover-info {{ $crumb['active'] ? 'text-primary' : 'text-muted' }} fw-bold">
                                {{ $crumb['name'] }}
                            </a>
                            @if(!$loop->last)
                                <i class="bi bi-chevron-right mx-3 text-gray-400 fs-9"></i>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            <span class="text-gray-400 fw-semibold fs-7">

                @php
                    $key = match(true) {
                        (bool)$selectedId   => 'file-manager::messages.file',
                        (bool)$selectedType => 'file-manager::messages.folder',
                        default             => 'file-manager::messages.category',
                    };
                @endphp

                {{-- This single line handles the number and the word together --}}
                {{ trans_choice($key, $totalCount, ['count' => $totalCount]) }}

            </span>
        </div>
        @if($view === 'items')
            @if($isCreating)
                <div class="card card-flush shadow-sm my-6 p-6 border-bottom-2 border-gray-400 rounded-0 border-bottom-dashed">
                    <h5 class="text-primary mb-4">New {{ str(class_basename($selectedType))->headline() }}</h5>

                    <div class="row g-4">
                        {{-- Dynamic Fields from Config --}}
                        @php
                            $fields = config("file-manager.forms.{$selectedType}") ?? config("file-manager.forms.default");
                        @endphp

                        <div class="row g-6">
                            @if(!empty($options) && !$selectedId)
                                {{-- Selection Area --}}
                                <div class="col-12">
                                    <label class="form-label fw-bold text-gray-700">
                                        {{ __('Which :type does this belong to?', ['type' => __('file-manager::messages.models.' .str(class_basename($selectedType))->headline()->snake()->lower()->toString())]) }}
                                    </label>

                                    <div class="d-flex flex-wrap gap-3 mt-2">
                                        @foreach($options as $option)
                                            <label
                                                    class="form-check form-check-custom form-check-solid form-check-sm cursor-pointer rounded p-3 me-2">
                                                <input class="form-check-input" type="radio"
                                                       wire:model="selectedId" @checked(count($options) ==1)
                                                       value="{{ $option['id'] }}" id="opt_{{ $option['id'] }}"/>
                                                <label class="form-check-label fw-semibold text-gray-800 ms-2"
                                                       for="opt_{{ $option['id'] }}">
                                                    {{ $option['label'] }}
                                                </label>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('selectedId') <span class="text-danger fs-7">{{ $message }}</span> @enderror
                                </div> {{-- Loop through fields from config --}}
                            @endif

                            @if(isset($fields['fields']))
                                @foreach($fields['fields'] as $key => $settings)
                                    <div class="{{ $settings['class'] ?? 'col-md-12' }} mb-3">
                                        <label class="form-label fw-bold">{{ $settings['label'] }}</label>

                                        @if($settings['type'] === 'select')
                                            {{-- SELECT TYPE --}}
                                            <select wire:model="formData.{{ $key }}"
                                                    class="form-select form-select-solid">
                                                <option value="">-- Select {{ $settings['label'] }} --</option>
                                                @foreach($settings['options'] as $value => $label)
                                                    {{-- Handles both ['Value'] and ['key' => 'Value'] --}}
                                                    <option value="{{ is_numeric($value) ? $label : $value }}">
                                                        {{ $label }}
                                                    </option>
                                                @endforeach
                                            </select>

                                        @elseif($settings['type'] === 'textarea')
                                            {{-- TEXTAREA TYPE --}}
                                            <textarea wire:model="formData.{{ $key }}"
                                                      class="form-control form-control-solid"
                                                      rows="3"
                                                      placeholder="Enter {{ strtolower($settings['label']) }}..."></textarea>

                                        @else
                                            {{-- DEFAULT TEXT/DATE/NUMBER TYPE --}}
                                            <input type="{{ $settings['type'] ?? 'text' }}"
                                                   wire:model="formData.{{ $key }}"
                                                   class="form-control form-control-solid"
                                                   placeholder="Enter {{ strtolower($settings['label']) }}...">
                                        @endif

                                        @error("formData.{$key}")
                                        <span class="text-danger fs-7">{{ $message }}</span>
                                        @enderror
                                    </div>
                                @endforeach
                            @endif

                            {{-- Loop through fields from config --}}
                            @if(isset($fields['custom_property']))
                                @foreach($fields['custom_property'] as $key => $settings)

                                    <div class="{{ $settings['class'] ?? 'col-md-12' }} mb-3">
                                        <label class="form-label fw-bold">{{ $settings['label'] }}</label>

                                        @if($settings['type'] === 'select')
                                            {{-- SELECT TYPE --}}
                                            <select wire:model="customProperty.{{ $key }}"
                                                    class="form-select form-select-solid">
                                                <option value="">-- Select {{ $settings['label'] }} --</option>
                                                @foreach($settings['options'] as $value => $label)
                                                    {{-- Handles both ['Value'] and ['key' => 'Value'] --}}
                                                    <option value="{{ is_numeric($value) ? $label : $value }}">
                                                        {{ $label }}
                                                    </option>
                                                @endforeach
                                            </select>

                                        @elseif($settings['type'] === 'textarea')
                                            {{-- TEXTAREA TYPE --}}
                                            <textarea wire:model="customProperty.{{ $key }}"
                                                      class="form-control form-control-solid"
                                                      rows="3"
                                                      placeholder="Enter {{ strtolower($settings['label']) }}..."></textarea>

                                        @else
                                            {{-- DEFAULT TEXT/DATE/NUMBER TYPE --}}
                                            <input type="{{ $settings['type'] ?? 'text' }}"
                                                   wire:model="customProperty.{{ $key }}"
                                                   class="form-control form-control-solid"
                                                   placeholder="Enter {{ strtolower($settings['label']) }}..."/>
                                        @endif

                                        @error("customProperty.{$key}")
                                        <span class="text-danger fs-7">{{ $message }}</span>
                                        @enderror
                                    </div>
                                @endforeach
                            @endif
                            {{-- APPENDED: File Upload Section (Always at the end) --}}
                            <div class="col-12 mt-6">
                                <label for="finalUpload" class="col-md-12">
                                    <div class="p-5 border border-dashed rounded text-center border-primary cursor-pointer">
                                        <input type="file" wire:model="upload" id="finalUpload" class="d-none">

                                        @if($upload)
                                            <div class="text-success mb-2">
                                                <i class="bi bi-file-check-fill fs-1"></i>
                                                <span class="fw-bold">{{ $upload->getClientOriginalName() }}</span>
                                            </div>
                                        @else
                                            <i class="bi bi-cloud-upload fs-1 text-muted mb-2"></i>
                                            <p class="text-muted small">Click to select the document for this record</p>
                                        @endif

                                        <label for="finalUpload"
                                               class="btn btn-sm {{ $upload ? 'btn-success' : 'btn-outline-primary' }}">
                                            {{ $upload ? 'Change File' : 'Choose File' }}
                                        </label>
                                    </div>
                                    @error('upload')
                                    <div class="text-danger fs-7 mt-1">{{ $message }}</div>
                                    @enderror
                                </label>
                            </div>

                            {{-- Action Buttons --}}
                            <div class="col-12 text-end mt-4">
                                <button wire:click="$set('isCreating', false)"
                                        class="btn btn-sm btn-light-primary fw-bolder me-2">
                                    Cancel
                                </button>
                                <button wire:click="storeRecordWithFile" class="btn btn-sm btn-primary fw-bolder me-2">
                                    <i class="bi bi-save"></i> Save & Upload
                                </button>
                            </div>
                            @if (session()->has('error'))
                                <div class="alert alert-danger d-flex align-items-center p-5 mb-5">
                                    <i class="bi bi-exclation-triangle fs-2hx text-danger me-4"></i>
                                    <div class="d-flex flex-column">
                                        <h4 class="mb-1 text-danger">Save Failed</h4>
                                        <span>{{ session('error') }}</span>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        @endif
        <div class="row g-6 g-xl-9">
            @forelse($items as $item)
                <div class="col-md-3 col-lg-2 col-xxl-2">
                    @php
                        $isFolder = $item['is_folder'] ?? false;
                        $isFlat = $item['is_flat'] ?? false;

                        if ($isFolder) {
                            $nextView = ($view === 'root' && $isFlat) ? 'items' : ($view === 'root' ? 'folder' : 'items');
                        }
                    @endphp

                    <div class="card h-100 flex-center border-dashed p-8 cursor-pointer shadow-none hover-elevate-up"
                         @if($isFolder)
                             wire:click="navigate('{{ $item['view'] ?? 'items' }}', '{{ addslashes($item['type']) }}', '{{ $item['id'] ?? '' }}')"
                            @endif>

                        <div class="mb-4">
                            @if($isFolder)
                                <i class="bi {{ $item['icon'] }} fs-4x"></i>
                            @else
                                <a href="{{ $item['url'] }}" target="_blank"
                                   class="fs-7 fw-bolder text-gray-800 text-hover-primary d-block text-truncate">
                                    <i class="bi {{ $this->getFileIcon($item['extension']) }} fs-4x"></i>
                                </a>
                            @endif
                        </div>

                        <div class="text-center w-100">
                            @if($isFolder)
                                <div class="d-flex flex-column justify-content-between flex-grow-1">
                                    <span class="fs-7 fw-bolder text-gray-800 text-hover-primary d-block lh-1 mb-1">
                                        {{ $item['name'] }}
                                    </span>
                                    <span class="fs-9 text-gray-400 fw-bold">
                                        {{ trans_choice('file-manager::messages.record', $item['count'], ['count' => $item['count']]) }}
                                    </span>
                                </div>
                            @else
                                <a href="{{ $item['url'] }}" target="_blank"
                                   class="fs-7 fw-bolder text-gray-800 text-hover-primary d-block text-truncate">
                                    {{ $item['name'] }}
                                </a>

                                <div class="d-flex flex-column mt-1">
                                    <span class="fs-9 text-gray-400 fw-bold">{{ $item['size'] }}</span>
                                    @if(isset($item['collection']))
                                        <span
                                                class="badge badge-light-dark fs-10 mt-2 align-self-center px-2 py-1">
                                            {{ strtoupper($item['collection']) }}
                                        </span>
                                    @endif
                                    {{-- Display Custom Properties --}}
                                    @if(!empty($item['custom']))
                                        <div class="mt-2 d-flex flex-wrap justify-content-center gap-1">
                                            @foreach($item['custom'] as $key => $value)
                                                @if($value)
                                                    {{-- Only show if value exists --}}
                                                    <span class="badge badge-light-info px-2 py-1"
                                                          title="{{ is_array($value) ? implode(', ', $value) : $value }}">
                                                    {{ str(is_array($value) ? implode(', ', $value) : $value)->limit(25) }}
                                                </span>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

            @empty
                <div class="col-12">
                    <div class="d-flex flex-column flex-center py-20">
                        @php
                            $setupMissing = false;

                                // 1. Ensure $selectedType is not empty
                                // 2. Ensure it's not a virtual label like "model_group" or "all"
                                // 3. Ensure the class actually exists in the system
                                if ($selectedType && class_exists($selectedType)) {
                                    $setupMissing = !method_exists(new $selectedType, 'media');
                                }
                        @endphp

                        @if($setupMissing)
                            @if(!app()->isProduction())
                                {{-- Developer View (What you see now) --}}
                                <i class="bi bi-gear-wide-connected fs-5x text-warning mb-5"></i>
                                <h3 class="text-gray-800 fw-bold">Development Notice</h3>
                                <p class="text-gray-600 text-center mw-350px">
                                    The model <code>{{ class_basename($selectedType) }}</code> is missing the <code>InteractsWithMedia</code>
                                    trait.
                                    <br><span class="text-muted fs-8">This is only visible in local/testing.</span>
                                </p>
                            @else
                                {{-- Production View (Helpful for User + Developer) --}}
                                <i class="bi bi-shield-lock fs-5x text-gray-300 mb-5"></i>
                                <h3 class="text-gray-700 fw-bold">Feature Unavailable</h3>
                                <p class="text-gray-600 text-center mw-400px">
                                    File management for this section hasn't been fully configured yet.
                                    Please share the error code below with your administrator.
                                </p>
                                <div class="badge badge-light-danger fs-8 fw-bold">
                                    ERR_MDL_CONFIG: {{ strtoupper(class_basename($selectedType)) }}
                                </div>
                            @endif
                        @elseif($search)
                            <i class="bi bi-search fs-5x text-gray-300 mb-5"></i>
                            <h3 class="text-gray-600 fw-bold">{{ __('file-manager::messages.no_results_found', ['search' => $search]) }}</h3>
                            <p class="text-gray-400">{{ __('file-manager::messages.try_checking_spelling') }}</p>

                            <button wire:click="$set('search', '')"
                                    class="btn btn-sm btn-light-primary mt-3">
                                <i class="bi bi-x-lg me-2"></i>{{ __('file-manager::messages.clear_search') }}
                            </button>
                        @else
                            <i class="bi bi-folder2-open fs-5x text-gray-300 mb-5"></i>
                            <h3 class="text-gray-600 fw-bold">{{ __('file-manager::messages.folder_is_empty') }}</h3>
                            <p class="text-gray-400">{{ __('file-manager::messages.no_media_matching') }}</p>

                            @if($view !== 'root')
                                <button wire:click="navigate('folder')"
                                        class="btn btn-sm btn-light-primary mt-3">
                                    {{ __('file-manager::messages.back_to_root') }}
                                </button>
                            @endif
                        @endif
                    </div>
                </div>
            @endforelse
        </div>
    </div>
</div>
