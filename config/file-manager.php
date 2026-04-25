<?php

use App\Models\Certification;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Global Model Definitions
    |--------------------------------------------------------------------------
    | These models will appear as main folders in the "Root" view of the
    | File Manager.
    */
    'models' => [
        /**
         * ModelNamespace => [
         *      'label'        => 'Label',
         *      'title_column' => 'Folder Name',
         *      'icon'         => 'bi-briefcase-fill text-primary',
         *      'flat'         => false,
         *      'filters'      => [
         *          'collections' => ['avatars', 'documents', 'contracts'],
         *          'custom_properties' => [
         *               // 'is_hidden' => false, // Only show if 'is_hidden' in custom_properties is false
         *          ],
         *      ],
         * ],
         */
        User::class => [
            'label' => 'Staff Directory', // Label in the UI
            'title_column' => 'relation.name',           // Column to use for folder name and for search you can use relations also.
            'icon' => 'bi-people-fill text-info',
            'flat' => false,            // False = show files group by user names, True= Show all user files combined
            'filters' => [
                'collections' => ['avatars', 'documents', 'contracts'],
                'custom_properties' => [
                    // 'is_hidden' => false, // Only show if 'is_hidden' in custom_properties in media table is false
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contextual Grouping (Deep Vault Logic)
    |--------------------------------------------------------------------------
    | Define how related files are aggregated. This is used when you pass
    | a 'model' to the component.
    */
    'relationships' => [
        /**
         * USER MODEL EXAMPLE
         * When viewing a specific User, show their files AND files belonging to their related models.
         * Example:
         * ModelNamespace => function($model) {
         * return [
         *      MainModelNamespace         => [$model->id],
         *      Relation1ModelNamespace    => $model->relation1()->pluck('id'),
         *      Relation2DModelNamespace   => $model->relation2()->pluck('id'),
         *      Relation3ModelNamespace    => $model->relation3()->pluck('id'),
         * ];
         * },
         */
        /*
         \App\Models\User::class => function ($user) {
            return [
                \App\Models\User::class => [$user->id],
                \App\Models\Certification::class => $user->certifications()->pluck('id'),
                \App\Models\IdentityProof::class => $user->identities()->pluck('id'),
                \App\Models\Payroll::class => $user->payrolls()->pluck('id'),
            ];
        },
        */
    ],

    /*
    |--------------------------------------------------------------------------
    | Forms
    |--------------------------------------------------------------------------
    | The package allows to add file from file manager.
    | Just list all the fields, type and it will capture data and stores it to the table.
    */
    'forms' => [
        User::class => [
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

        Certification::class => [
            'custom_property' => [
                'remark' => ['label' => 'Description', 'type' => 'textarea', 'col' => ' form-control col-md-6'], // to add remark in media table directly
            ],
        ],
    ],


    /*
     |--------------------------------------------------------------------------
     | Custom Properties configuration
     |--------------------------------------------------------------------------
     | If you want to show custom properties along with file name which is stored in media table
     */
    'visible_custom_properties' => ['remark',],

    /*
     |--------------------------------------------------------------------------
     | Media Download URL Configuration
     |--------------------------------------------------------------------------
     | The route name used to serve private media.
     | If using a private local disk, you must define this route in your app accepting uuid / media as a parameter
     */
    'download_route' => 'file-manager.download',

    /*
    |--------------------------------------------------------------------------
    | Pagination Configuration
    |--------------------------------------------------------------------------
     | Maximum number of items to show per page (if you add pagination later).
     */
    'per_page' => 25,

    /*
    |--------------------------------------------------------------------------
    | Folder Icon Configuration
    |--------------------------------------------------------------------------
    | Map your Model Classes to specific Bootstrap or FontAwesome icons.
    */
    'icons' => [
        User::class => 'bi-person-badge text-info',
        // Certification::class       => 'bi-clipboard-check text-warning',
        'default' => 'bi-folder-fill text-primary',
    ],
];
