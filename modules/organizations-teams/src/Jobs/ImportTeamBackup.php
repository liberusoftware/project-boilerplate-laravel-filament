<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\Contracts\TeamJobNotifier;
use Liberu\Foundation\Organizations\Services\ImportTeamBackupService;
use Throwable;

/**
 * Imports an uploaded backup zip (from another environment) into a new team,
 * off the request cycle. Stages the stored upload to a local temp file (zip
 * reads need a local path), runs the import, notifies the initiating admin.
 */
class ImportTeamBackup implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $disk,
        public string $path,
        public ?string $name,
        public int $importerId,
    ) {}

    public function handle(ImportTeamBackupService $service): void
    {
        $userModel = Jetstream::userModel();
        $importer = $userModel::findOrFail($this->importerId);

        $tmp = tempnam(sys_get_temp_dir(), 'teamimport');
        file_put_contents($tmp, (string) Storage::disk($this->disk)->get($this->path));

        try {
            $team = $service->import($tmp, $this->name, $importer);
            $this->notify('Import complete', true, "Imported into new team “{$team->name}” (#{$team->id}).", $importer);
        } catch (Throwable $e) {
            $this->notify('Import failed', false, $e->getMessage(), $importer);
            throw $e;
        } finally {
            @unlink($tmp);
        }
    }

    private function notify(string $title, bool $success, string $body, Model $user): void
    {
        app(TeamJobNotifier::class)->notify($user, $title, $body, $success);
    }
}
