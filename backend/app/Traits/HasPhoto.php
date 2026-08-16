<?php

namespace App\Traits;

use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

trait HasPhoto
{
    /**
     * Boots the trait for the eloquent models.
     */
    public static function bootHasPhoto()
    {
        // Delete Old Photos.
        static::deleting(function ($model) {
            $model->deleteImages();
        });
    }

    /**
     * Gets the model photos.
     *
     * @return mixed
     */
    public function photos()
    {
        return $this->morphMany(Photo::class, 'photoable')->where('type', 'gallery');
    }

    /**
     * Gets the model photos.
     *
     * @return mixed
     */
    public function galleyPhoto()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'gallery');
    }
    /**
     * @return mixed
     */
    public function photo()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'featured');
    }

    /**
     * @return mixed
     */
    public function identity()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'identity');
    }

    /**
     * @return mixed
     */
    public function family()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'family');
    }

    /**
     * @return mixed
     */
    public function licence()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'licence');
    }

    /**
     * @return mixed
     */
    public function personal()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'personal');
    }  
    
    /**
     * @return mixed
     */
    public function car_licence()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'car_licence');
    }   
    /**
     * @return mixed
     */
    public function car_photo()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'car_photo');
    }   
    /**
     * @return mixed
     */
    public function car_photo2()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'car_photo2');
    }                
    /**
     * @return mixed
     */
    public function certification()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'certification');
    }

    /**
     * Adds an image or multiple images to the model.
     *
     * @param $file
     * @param $path
     * @param string $type
     * @return static
     */
    public function storeImage($file, $path, $type = 'featured')
    {
        /*$image = Image::make($file);
        $image->fit(1900, 750, function ($constraint) {
            $constraint->aspectRatio();
        });
        Storage::disk('public')->put($path, (string) $image->encode());*/
        //Storage::disk('public')->put($path, Image::make($file)->encode('jpg', 50));
        $name = $file->store($path, 'public');
        $photo =  $this->photos()
            ->create(['path' =>$name, 'type' => $type]);
        return $photo;
    }

    /**
     * Replaces the current image with a new one.
     * @param $file
     * @param $path
     * @param string $type
     */
    public function replaceImage($file, $path, $type = 'featured')
    {
        if ($type == 'featured' && $this->photo) {
            $this->photo->delete();
        } elseif ($type == 'identity' && $this->identity) {
            $this->identity->delete();
        } elseif ($type == 'family' && $this->family) {
            $this->family->delete();
        } elseif ($type == 'licence' && $this->licence) {
            $this->licence->delete();
        } elseif ($type == 'certification' && $this->certification) {
            $this->certification->delete();
        } elseif ($type == 'personal' && $this->personal) {
            $this->personal->delete();
        } elseif ($type == 'car_licence' && $this->car_licence) {
            $this->car_licence->delete();
        } elseif ($type == 'car_photo' && $this->car_photo) {
            $this->car_photo->delete();
        } elseif ($type == 'car_photo2' && $this->car_photo2) {
            $this->car_photo2->delete();
        } else {
            $this->photos->each(function ($photo) {
                $photo->delete();
            });
        }
        $this->storeImage($file, $path, $type);
    }


    public function deleteImage()
    {
        Storage::disk('public')->delete($this->photo->path);
        $this->photo()->delete();
    }

    public function deleteIdentityImage()
    {
        Storage::disk('public')->delete($this->identity->path);
        $this->identity()->delete();
    }

    public function deleteFamilyImage()
    {
        Storage::disk('public')->delete($this->family->path);
        $this->family()->delete();
    }

    public function deleteLicenceImage()
    {
        Storage::disk('public')->delete($this->licence->path);
        $this->licence()->delete();
    }
    public function deleteCertificationImage()
    {
        Storage::disk('public')->delete($this->certification->path);
        $this->certification()->delete();
    }
    public function deleteCheck()
    {
        if ($this->photo) {
            $this->deleteImage();
        }
        if ($this->identity) {
            $this->deleteIdentityImage();
        }
        if ($this->family) {
            $this->deleteFamilyImage();
        }
        if ($this->licence) {
            $this->deleteLicenceImage();
        }
        if ($this->certification) {
            $this->deleteCertificationImage();
        }
    }

    /**
     * @return mixed
     */
    public function getThumbnailAttribute()
    {
        return $this->photo->getThumbnail();
    }

    /**
     * Deletes all images.
     */
    public function deleteImages()
    {
        $this->photos->each(function ($photo) {
            $photo->delete();
        });
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function getImagesAttribute()
    {
        $photos = $this->photos;
        if (! $photos || ! $photos->count()) {
            return collect([]);
        }

        return $photos->map(function ($photo) {
            return $photo;
        });
    }

    /**
     * @return null|string
     */
    public function getImageAttribute()
    {
        $photo = $this->photo;
        if (! $photo) {
            $rawImage = $this->attributes['image'] ?? $this->attributes['profile_image'] ?? null;
            if (!$rawImage) {
                return null;
            }
            if (filter_var($rawImage, FILTER_VALIDATE_URL)) {
                return $rawImage;
            }

            return asset(ltrim($rawImage, '/'));
        }

        return asset('storage/' . $photo->path);
    }
    /**
     * @return null|string
     */
    public function getGalleryAttribute()
    {
        $galleyPhoto = $this->galleyPhoto;
        if (! $galleyPhoto) {
            return null;
        }

        return asset('storage/' . $galleyPhoto->path);
    }    

    /**
     * @return null|string
     */
    public function getIdentityImageAttribute()
    {
        $photo = $this->identity;
        if (! $photo) {
            return null;
        }

        return asset('storage/' . $photo->path);
    }

    /**
     * @return null|string
     */
    public function getFamilyImageAttribute()
    {
        $photo = $this->family;
        if (! $photo) {
            return null;
        }

        return asset('storage/' . $photo->path);
    }

    /**
     * @return null|string
     */
    public function getLicenceImageAttribute()
    {
        $photo = $this->licence;
        if (! $photo) {
            return null;
        }

        return asset('storage/' . $photo->path);
    }

    /**
     * @return null|string
     */
    public function getPersonalImageAttribute()
    {
        $photo = $this->personal;
        if (! $photo) {
            return null;
        }

        return asset('storage/' . $photo->path);
    } 
    /**
     * @return null|string
     */
    public function getCarLicenceImageAttribute()
    {
        $photo = $this->car_licence;
        if (! $photo) {
            return null;
        }

        return asset('storage/' . $photo->path);
    }  
    /**
     * @return null|string
     */
    public function getCarPhotoImageAttribute()
    {
        $photo = $this->car_photo;
        if (! $photo) {
            return null;
        }

        return asset('storage/' . $photo->path);
    } 
    /**
     * @return null|string
     */
    public function getCarPhotoImage2Attribute()
    {
        $photo = $this->car_photo2;
        if (! $photo) {
            return null;
        }

        return asset('storage/' . $photo->path);
    }               
    /**
     * @return null|string
     */
    public function getCertificationImageAttribute()
    {
        $photo = $this->certification;
        if (! $photo) {
            return null;
        }

        return asset('storage/' . $photo->path);
    }
    /**
     * @return null|string
     */
    public function getPathAttribute()
    {
        $photo = $this->photo;
        if (! $photo) {
            return null;
        }

        return (public_path().'/storage/' .$photo->path);
    }
}
