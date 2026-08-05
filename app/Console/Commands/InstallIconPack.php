<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class InstallIconPack extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    public $signature = '2fauth:install-icon-pack
        {zip : Path to a .zip file containing icon images}
        {name : The icon pack name (becomes the directory under storage/app/iconPacks)}';

    /**
     * The console command description.
     *
     * @var string
     */
    public $description = 'Extract a .zip of icons into a local icon pack so the storage LogoLib driver can serve them offline.';

    /**
     * File extensions accepted into a pack (matches StorageLogoLib).
     */
    protected array $extensions = ['svg', 'png', 'webp', 'jpg', 'jpeg', 'bmp'];

    /**
     * Execute the console command.
     */
    public function handle() : int
    {
        $zipPath = (string) $this->argument('zip');
        $rawName = (string) $this->argument('name');

        // Pack names become a directory name; sanitise to avoid path traversal.
        $name = Str::slug($rawName);
        if ($name === '' || str_starts_with($name, '.')) {
            $this->error(sprintf('Invalid pack name "%s": it must produce a non-empty, slug-safe directory name.', $rawName));

            return self::FAILURE;
        }

        if (! is_readable($zipPath)) {
            $this->error(sprintf('Cannot read zip file at "%s".', $zipPath));

            return self::FAILURE;
        }

        $zip = new ZipArchive;
        if (($code = $zip->open($zipPath)) !== true) {
            $this->error(sprintf('Could not open zip (ZipArchive code %s).', $code));

            return self::FAILURE;
        }

        $disk      = Storage::disk('iconPacks');
        $extracted = 0;
        $skipped   = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $entryName = $stat['name'];

            // Skip directories and any nested paths: packs are flat collections
            // of <service>.svg style files. Reject entries that escape the
            // pack root.
            if (str_ends_with($entryName, '/')) {
                continue;
            }
            $basename = basename($entryName);
            if ($basename === '' || str_starts_with($basename, '.')) {
                continue;
            }

            $extension = strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));
            if (! in_array($extension, $this->extensions, true)) {
                $skipped++;

                continue;
            }

            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                continue;
            }

            $relative = $name . '/' . $basename;
            $disk->put($relative, $contents);
            $extracted++;
        }

        $zip->close();

        $this->info(sprintf('Installed icon pack "%s": %d file(s) extracted, %d skipped.', $name, $extracted, $skipped));
        $this->comment('Users can now select this pack via Preferences > Icons > Icon pack, or set ICON_OFFLINE_ONLY=true to force local packs only.');

        return self::SUCCESS;
    }
}
