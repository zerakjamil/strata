<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Strata Storage Path
    |--------------------------------------------------------------------------
    |
    | The directory where binary segment files (.seg) and the active manifest
    | will be stored.
    |
    */
    'path' => storage_path('strata'),

    /*
    |--------------------------------------------------------------------------
    | Vector Dimensions
    |--------------------------------------------------------------------------
    |
    | Dimensional length of dense vector embeddings (e.g. 384 for MiniLM/bge-small).
    |
    */
    'vector_dimension' => 384,

    /*
    |--------------------------------------------------------------------------
    | Reciprocal Rank Fusion Constant
    |--------------------------------------------------------------------------
    |
    | Smoothing constant k for reciprocal rank fusion scoring (default: 60).
    |
    */
    'rrf_k' => 60,

    /*
    |--------------------------------------------------------------------------
    | Memory-Mapped I/O Mode
    |--------------------------------------------------------------------------
    |
    | When enabled and PHP ext-ffi is installed, Strata uses zero-copy mmap.
    | Set to false to force stream-based file reading.
    |
    */
    'mmap' => true,
];
