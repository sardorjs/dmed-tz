<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        // Fire LongWaitDetected event when jobs wait longer than these thresholds (seconds).
        // Upload: 30s threshold — user is waiting for "pending" → "done" feedback.
        // Delete: 60s — less time-sensitive, user already got 202.
        'redis:images-upload' => 30,
        'redis:images-delete' => 60,
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [

        // Handles ProcessImageUploadJob.
        // Heavy I/O: reads local temp file, streams upload to S3, updates DB.
        'supervisor-upload' => [
            'connection' => 'redis',
            'queue' => ['images-upload'],
            'balance' => 'auto',
            // 'time' scales workers to minimize job wait time — better than
            // 'size' for latency-sensitive workloads where queue depth alone
            // does not reflect how long each job actually takes.
            'autoScalingStrategy' => 'time',
            // 128 MB: PHP worker base ~20 MB + up to 5 MB file in memory
            // + Eloquent/S3 SDK overhead. 128 MB gives comfortable headroom.
            'memory' => 128,
            // Restart worker after 1 hour to prevent gradual memory leaks
            // from long-running PHP processes handling many file uploads.
            'maxTime' => 3600,
            // Restart after 500 jobs as a safety net for slow leaks.
            // At 11.5 jobs/sec average a worker hits this in ~43s — fine.
            'maxJobs' => 500,
            // tries/timeout are defined on the Job class and take precedence.
            // These supervisor-level values are fallback defaults only.
            'tries' => 1,
            'timeout' => 120,
            'nice' => 0,
        ],

        // Handles DeleteImageJob.
        // Lighter than upload: no local file read, just S3 delete + DB delete.
        'supervisor-delete' => [
            'connection' => 'redis',
            'queue' => ['images-delete'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            // 64 MB: delete jobs do not touch local disk — S3 SDK + Eloquent
            // only. 64 MB is sufficient with margin.
            'memory' => 64,
            'maxTime' => 3600,
            // 1000 jobs before restart: delete jobs are lightweight,
            // memory profile stays flat across many iterations.
            'maxJobs' => 1000,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        // Handles everything else dispatched to the default queue.
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'memory' => 128,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-upload' => [
                // Min 5: always keep 5 workers warm.
                // 1M/day ÷ 86400s × 0.2s/job ≈ 2.3 workers at average load.
                // 5 handles 2x average spike without any scaling delay.
                'minProcesses' => 5,
                // Max 30: peak burst ~100 req/sec × 0.2s/job = 20 workers needed.
                // 30 gives 50% headroom above peak without exhausting server CPU.
                'maxProcesses' => 30,
                // Scale by up to 5 workers per rebalancing cycle.
                // Aggressive enough to absorb bursts, not so fast it thrashes.
                'balanceMaxShift' => 5,
                // Rebalance every 3 seconds — default, avoids oscillation.
                'balanceCooldown' => 3,
            ],
            'supervisor-delete' => [
                // Min 2: deletes are infrequent; 2 workers idle is cheap.
                'minProcesses' => 2,
                // Max 10: bulk-delete scenarios (user deletes entire gallery).
                // S3 delete ~50ms → 10 workers = 200 deletes/sec capacity.
                'maxProcesses' => 10,
                'balanceMaxShift' => 3,
                'balanceCooldown' => 3,
            ],
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-upload' => [
                // Local dev: 1-2 workers — enough to test the pipeline,
                // saves RAM on a dev machine.
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-delete' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
