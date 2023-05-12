<?php

namespace PatrykSawicki\Helper\app\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use PatrykSawicki\Helper\app\Traits\files;

trait uploads
{
    use files;

    /**
     * Add image.
     *
     * @param UploadedFile $uploadedFile
     * @param string $location e.g. order_files
     * @param string $relationName e.g. files
     * @param array $categories
     * @param int|null $max_width
     * @param int|null $max_height
     * @param bool $externalRelation
     * @param array $options
     * @return Model
     */
    public function addUpload(UploadedFile $uploadedFile, string $location='uploads', string $relationName='files',
        array $categories=[], int $max_width=null, int $max_height=null, bool $externalRelation = true, array $options=[])
    : Model
    {
        if(explode('/', $uploadedFile->getMimeType())[0] != 'image' ||
           str_contains($uploadedFile->getMimeType(), 'svg'))
        {
            $file=$this->addFile(file: $uploadedFile, location: $location, relationName: $relationName,
                max_width: $max_width, max_height: $max_height, externalRelation: $externalRelation, options: $options);

            if(!empty($categories))
                $file->categories()->sync($categories);

            return $file;
        }

        $file=$this->addFile(file: $uploadedFile, location: $location, relationName: $relationName,
            max_width: $max_width, max_height: $max_height, externalRelation: $externalRelation, options: $options);

        if(!empty($categories))
            $file->categories()->sync($categories);

        foreach(config('filesSettings.thumbnailSizes', []) as $thumbnailSize)
        {
            if($file->width > $thumbnailSize['width'] && $file->height > $thumbnailSize['height'])
                $file->addFile(file: $uploadedFile, location: $location, relationName: 'thumbnails',
                    max_width: $thumbnailSize['width'], max_height: $thumbnailSize['height'], options: $options);
        }

        return $file;
    }

    /**
     * Add uploads.
     *
     * @param array $files
     * @param string $location e.g. order_files
     * @param string $relationName e.g. files
     * @param array $categories
     */
    public function addUploads(array $files, string $location='uploads', string $relationName='files', array $categories=[], array $options=[]): void
    {
        foreach($files as $file)
            $this->addUpload($file, $location, $relationName, $categories, options: $options);
    }

    /**
     * Regenerate thumbnails.
     */
    public function regenerateThumbnails(string $filesModel, string $location='uploads'): void
    {
        $thumbnailSizes = config('filesSettings.thumbnailSizes', []);
        $thumbnailSizes = collect($thumbnailSizes);

        /*Remove old, not currently needed thumbnails.*/
        $thumbnails = $filesModel::where('model_type', $filesModel)->get();
        foreach($thumbnails as $thumbnail)
        {
            $thumbnailSize = $thumbnailSizes->where('width', $thumbnail->width)->where('height', $thumbnail->height)->first();
            $thumbnailSize ??= $thumbnailSizes->where('width', $thumbnail->width)->first();
            $thumbnailSize ??= $thumbnailSizes->where('height', $thumbnail->height)->first();

            if(!$thumbnailSize)
                $thumbnail->delete();
        }

        $files = $filesModel::where(function(Builder $query) use ($filesModel) {
            $query->where('model_type', '!=', $filesModel)->orWhereNull('model_type');
        })->where('mime_type', 'like', '%image%')->where('mime_type', 'not like', '%svg%')->get();

        foreach($files as $file)
        {
            $filePath = storage_path('app' . $file->file);

            /*Check if file exist.*/
            if(!file_exists($filePath))
                continue;

            $uploadedFile = new UploadedFile(
                $filePath,
                $file->name,
                $file->mime_type,
                0,
                true
            );

            [$fileWidth, $fileHeight] = getimagesize($filePath);

            foreach($thumbnailSizes as $thumbnailSize)
            {
                if(($file->thumbnails->count() == 0 || ($file->thumbnails->where('width', $thumbnailSize['width'])->count() == 0 && $file->thumbnails->where('height', $thumbnailSize['height'])->count() == 0)) && ((is_null($thumbnailSize['width']) || $thumbnailSize['width'] < $fileWidth) && (is_null($thumbnailSize['height']) || $thumbnailSize['height'] < $fileHeight)))
                {
                    $file->addFile($uploadedFile, $location, 'thumbnails', $thumbnailSize['width'], $thumbnailSize['height']);
                }
            }
        }
    }
}
