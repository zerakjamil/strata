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
    | In-Process Persistent Worker Mode
    |--------------------------------------------------------------------------
    |
    | When running inside persistent runtimes (Laravel Octane / FrankenPHP /
    | Swoole / RoadRunner), Strata maintains active segment readers, stride
    | dictionaries, and bitmask handles hot in worker process memory.
    |
    */
    'persistent_worker' => true,
];
