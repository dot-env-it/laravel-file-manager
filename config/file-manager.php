<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Global Model Definitions
    |--------------------------------------------------------------------------
    | These models will appear as main folders in the "Root" view of the
    | File Manager.
    */
    'models' => [
        // Example: User Model Configuration
        \App\Models\User::class => [
            'foreign_key' => '',
            'label' => 'Staff Directory', // Label in the UI
            'title_column' => 'name',           // Column to use for folder name
            'icon' => 'bi-people-fill text-info',
            'flat' => false,            // False = show list of users first
            'filters' => [
                'collections' => ['avatars', 'documents', 'contracts'],
            ],
        ],

        // Example: Model Configuration
        /*
         *
        ModelNamespace => [
            'label'        => 'Label',
            'title_column' => 'Folder Name',
            'icon'         => 'bi-briefcase-fill text-primary',
            'flat'         => false,
            'filters'      => [
                'collections' => ['avatars', 'documents', 'contracts'],
                'custom_properties' => [
                    // 'is_hidden' => false, // Only show if 'is_hidden' in custom_properties is false
                ],
            ],
        ],
         */
    ],

    /*
    |--------------------------------------------------------------------------
    | Contextual Grouping (Deep Vault Logic)
    |--------------------------------------------------------------------------
    | Define how related files are aggregated. This is used when you pass
    | an 'owner' to the component.
    */
    'relationships' => [

        /**
         * USER MODEL EXAMPLE
         * When viewing a specific User, show their files AND files
         * belonging to their related models.
         */
//        \App\Models\User::class => function ($user) {
//            return [
//                \App\Models\User::class => [$user->id],
//                \App\Models\Certification::class => $user->certifications()->pluck('id'),
//                \App\Models\IdentityProof::class => $user->identities()->pluck('id'),
//                \App\Models\Payroll::class => $user->payrolls()->pluck('id'),
//            ];
//        },

        /**
         * MODEL EXAMPLE
         */
        /*
        ModelNamespace => function($model) {
            return [
                MainModelNamespace         => [$model->id],
                Relation1ModelNamespace    => $model->relation1()->pluck('id'),
                Relation2DModelNamespace   => $model->relation2()->pluck('id'),
                Relation3ModelNamespace    => $model->relation3()->pluck('id'),
            ];
        },
        */
    ],

    /*
    |--------------------------------------------------------------------------
    | Forms
    |--------------------------------------------------------------------------
    | The package allows to add file from file manager.
    | Just list all the fields type and it will capture data and stores it to the table.
    */
    'forms' => [
        \App\Models\User::class => [
            'fields' => [
                'name' => ['label' => 'Name', 'type' => 'text', 'class' => 'col-md-6'],
                'description' => ['label' => 'Description', 'type' => 'textarea', 'class' => 'col-md-6'],
                'document_date' => ['label' => 'Date of Issue', 'type' => 'date', 'class' => 'col-md-6'],
                'document_type' => [
                    'label' => 'Type of Document',
                    'type' => 'select',
                    'options' => [
                        'id_proof' => 'ID Proof',
                        'address_proof' => 'Address Proof',
                    ],
                    'class' => 'col-md-6',
                ],
            ],
        ],

        \App\Models\Certification::class => [
            'custom_property' => [
                'remark' => ['label' => 'Description', 'type' => 'textarea', 'col' => ' form-control col-md-6'], // to add remark in media table directly
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Model fallback
    |--------------------------------------------------------------------------
    | The package automatically detects Spatie's model, but you can override
    | it here if you use a custom class in your project.
    */
    'media_model' => env('FILE_MANAGER_MEDIA_MODEL', \Spatie\MediaLibrary\MediaCollections\Models\Media::class),

    'forms' => [
        // Default fields if a model isn't defined above
        'default' => [
            'name' => ['label' => 'Title/Name', 'type' => 'text', 'col' => 'col-md-12'],
        ],
    ],

    /**
     * The disk where Spatie Media is stored.
     */
    'disk' => env('MEDIA_DISK', 'public'),

    /**
     * Maximum number of items to show per page (if you add pagination later).
     */
    'per_page' => 25,

    /*
    |--------------------------------------------------------------------------
    | Folder Icon Configuration
    |--------------------------------------------------------------------------
    | Map your Model Classes to specific Bootstrap or FontAwesome icons.
    */
    'icons' => [
        \App\Models\User::class => 'bi-person-badge text-info',
        // \App\Models\Certification::class       => 'bi-clipboard-check text-warning',
        'default' => 'bi-folder-fill text-secondary',
    ],
];
