<?php

namespace Encore\Admin\Form\Field;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait ImageField
{
    /**
     * Intervention calls.
     *
     * @var array
     */
    protected $interventionCalls = [];

    /**
     * Thumbnail settings.
     *
     * @var array
     */
    protected $thumbnails = [];

    /**
     * Default directory for file to upload.
     *
     * @return string
     */
    public function defaultDirectory()
    {
        return config('admin.upload.directory.image');
    }

    /**
     * Execute Intervention calls.
     *
     * @param string $target
     *
     * @return string
     */
    public function callInterventionMethods($target)
    {
        if (!empty($this->interventionCalls)) {
            // Initialize ImageManager with Gd driver (or Imagick if configured)
            $manager = new ImageManager(new GdDriver());
            $image = $manager->read($target);

            foreach ($this->interventionCalls as $call) {
                call_user_func_array([$image, $call['method']], $call['arguments']);
                $image->save($target);
            }
        }

        return $target;
    }

    /**
     * Call intervention methods dynamically.
     *
     * @param string $method
     * @param array $arguments
     *
     * @throws \Exception
     *
     * @return $this
     */
    public function __call($method, $arguments)
    {
        if (static::hasMacro($method)) {
            return $this;
        }

        if (!class_exists(ImageManager::class)) {
            throw new \Exception('To use image handling and manipulation, please install [intervention/image] first.');
        }

        $this->interventionCalls[] = [
            'method' => $method,
            'arguments' => $arguments,
        ];

        return $this;
    }

    /**
     * Render an image form field.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function render()
    {
        $this->options([
            'allowedFileTypes' => ['image'],
            'msgPlaceholder' => trans('admin.choose_image')
        ]);

        return parent::render();
    }

    /**
     * Define thumbnail settings.
     *
     * @param string|array $name
     * @param int|null $width
     * @param int|null $height
     *
     * @return $this
     */
    public function thumbnail($name, int $width = null, int $height = null)
    {
        if (func_num_args() == 1 && is_array($name)) {
            foreach ($name as $key => $size) {
                if (count($size) >= 2) {
                    $this->thumbnails[$key] = $size;
                }
            }
        } elseif (func_num_args() == 3) {
            $this->thumbnails[$name] = [$width, $height];
        }

        return $this;
    }

    /**
     * Destroy original thumbnail files.
     *
     * @return void
     */
    public function destroyThumbnail()
    {
        if ($this->retainable) {
            return;
        }

        foreach ($this->thumbnails as $name => $_) {
            if (is_array($this->original)) {
                if (empty($this->original)) {
                    continue;
                }
                foreach ($this->original as $original) {
                    $this->destroyThumbnailFile($original, $name);
                }
            } else {
                $this->destroyThumbnailFile($this->original, $name);
            }
        }
    }

    /**
     * Remove thumbnail file from disk.
     *
     * @param string $original
     * @param string $name
     *
     * @return void
     */
    public function destroyThumbnailFile($original, $name)
    {
        $ext = @pathinfo($original, PATHINFO_EXTENSION);
        // Remove extension from file name to append thumbnail type
        $path = @Str::replaceLast('.'.$ext, '', $original);
        // Merge original name + thumbnail name + extension
        $path = $path.'-'.$name.'.'.$ext;

        if ($this->storage->exists($path)) {
            $this->storage->delete($path);
        }
    }

    /**
     * Upload file and delete original thumbnail files.
     *
     * @param UploadedFile $file
     *
     * @return $this
     */
    protected function uploadAndDeleteOriginalThumbnail(UploadedFile $file)
    {
        // Initialize ImageManager with Gd driver
        $manager = new ImageManager(new GdDriver());

        foreach ($this->thumbnails as $name => $size) {
            // Get extension type (e.g., .jpeg, .png)
            $ext = pathinfo($this->name, PATHINFO_EXTENSION);
            // Remove extension from file name to append thumbnail type
            $path = Str::replaceLast('.'.$ext, '', $this->name);
            // Merge original name + thumbnail name + extension
            $path = $path.'-'.$name.'.'.$ext;

            // Read image from uploaded file
            $image = $manager->read($file->getRealPath());

            $action = $size[2] ?? 'resize';

            // Handle resize or other actions
            if ($action === 'resize') {
                // Resize with aspect ratio and center on canvas
                $image->resize($size[0], $size[1], function ($constraint) {
                    $constraint->aspectRatio();
                })->resizeCanvas($size[0], $size[1], 'center', false, '#ffffff');
            } else {
                // Handle other actions if defined (e.g., crop, fit)
                call_user_func_array([$image, $action], [$size[0], $size[1]]);
            }

            // Save the thumbnail to storage
            $encodedImage = $image->toJpeg(); // Adjust format based on $ext if needed
            if (!is_null($this->storagePermission)) {
                $this->storage->put("{$this->getDirectory()}/{$path}", $encodedImage, $this->storagePermission);
            } else {
                $this->storage->put("{$this->getDirectory()}/{$path}", $encodedImage);
            }
        }

        $this->destroyThumbnail();

        return $this;
    }
}