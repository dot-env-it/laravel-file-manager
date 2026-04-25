<?php

declare(strict_types=1);

namespace DotEnvIt\FileManager\Interfaces;

interface FileManagerModelInterface
{
    /**
     * Get the foreign key column name that links to the owner model.
     * Example: 'matter_id'
     */
    public function getFileManagerForeignKey(): string;

    /**
     * Get the human-readable label for the file manager selection.
     * Example: 'John Doe' or 'Final Hearing Task'
     */
    public function getFileManagerLabel(): string;
}
