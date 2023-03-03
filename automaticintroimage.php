<?php
/**
 * @copyright  Copyright (c) 2022- Steven Trooster. All rights reserved.
 *             Based on a plugin created by Mattia Verga.
 * @license    GNU General Public License version 3, or later
 * @Joomla     For Joomla 3.10 and Joomla 4
 */
// no direct access
defined("_JEXEC") or die();

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\CMS\Categories\Categories;

class plgContentAutomaticIntroImage extends JPlugin
{
    /**
     * Load the language file on instantiation. Note this is only available in Joomla 3.1 and higher.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    private function convertAndDeleteImage($image_location, &$nb_converted, &$nb_moved, &$output_link, &$only_moved) {
        $only_moved = false;
        if (!str_starts_with($image_location, "images/")) {
            //FIXME: get parameter from media manager
            return false;
        }

        // Normalize name
        $output_link = strtolower(
            preg_replace(
                "/-thumb\./",
                "_thumb.",
                preg_replace("/_/", "-", $image_location)
            )
        );
        $image_location = JPATH_ROOT . "/" . $image_location;
        $output_location = JPATH_ROOT . "/" . $output_link;

        $folder = dirname($output_location);
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        if (
            str_ends_with($image_location, ".webp") ||
            str_ends_with($image_location, ".svg") ||
            str_ends_with($image_location, ".avif") ||
            str_ends_with($image_location, ".ico")
        ) {
            if ($output_location != $image_location) {
                // Normalize name: move the image
                rename($image_location, $output_location);
                $nb_moved++;
                $only_moved = true;
                return true;
            }
            return false;
        }

        $output_link = preg_replace(
            "/(\.jpg)|(\.png)|(\.jpeg)$|(\.gif)/",
            ".webp",
            $output_link
        );

        $output_location = preg_replace(
            "/(\.jpg)|(\.png)|(\.jpeg)$|(\.gif)/",
            ".webp",
            $output_location
        );

        // Create webp image
        $is_gif = false;
        if (file_exists($image_location)) {
            $info = getimagesize($image_location);
            $is_alpha = false;
            if ($info["mime"] == "image/jpeg") {
                $image = imagecreatefromjpeg($image_location);
            } elseif ($is_alpha = $info["mime"] == "image/gif") {
                $is_gif = true;
            } elseif ($is_alpha = $info["mime"] == "image/png") {
                $image = imagecreatefrompng($image_location);
            } else {
                Factory::getApplication()->enqueueMessage(
                    "Wrong image file type for {$image_location}",
                    "error"
                );
                return false;
            }

            if (!$is_gif) {
                if ($is_alpha) {
                    imagepalettetotruecolor($image);
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                }
                imagewebp($image, $output_location, 80); //TODO: configure quality level
            } else {
                $output = null;
                $retval = null;
                exec(
                    "convert {$image_location} {$output_location}",
                    $output,
                    $retval
                ); // PHP version does not support gif.
                if ($retval != 0) {
                    Factory::getApplication()->enqueueMessage(
                        join(" / ", $output),
                        "error"
                    );
                    return false;
                }
            }
            unlink($image_location);
            $nb_converted++;
            return true;
        }
        // If the file was not found, then it *should* have been converted before
        return true;
    }

    /**
        * Automatic creation of resized intro image from article full image
        *
        * @param   string   $context  The context of the content being passed to the
        plugin.
        * @param   mixed    $article  The JTableContent object that is
        being saved which holds the article data.
        * @param   boolean  $isNew    A boolean which is set to true if the content
        is about to be created.
        *
        * @return  boolean	True on success.
        */
    public function onContentBeforeSave($context, $article, $isNew)
    {
        // Check if we're saving an article
        $allowed_contexts = ["com_content.article", "com_content.form"];

        if (!in_array($context, $allowed_contexts)) {
            return true;
        }

        $article->created = $article->publish_up;

        if ($article->metadesc == "") {
            $article->setError("No meta description!");
            return false;
        }

        $categories = Categories::getInstance("Content", []);
        $category_alias = $categories->get($article->catid)->alias;

        if ($category_alias == "actualites" || $category_alias == "dossiers") {
            $article->setError(
                "Invalid category (\"Dossiers\" or \"Actualités\")"
            );
            return false;
        }

        $tagsHelper = new TagsHelper();
        $tags = join(", ", $tagsHelper->getTagNames($article->newTags));

        if ($tags == "") {
            $article->setError(
                "No tag has been set!",
            );
            return false;
        } else {
            $article->metakey = $tags;
            Factory::getApplication()->enqueueMessage(
                "Keywords sucessfully set to \"{$article->metakey}\"",
                "message"
            );
        }

        // Treatment of the images
        $images = json_decode($article->images);

        // Check ImageMagick
        if (!extension_loaded("imagick")) {
            Factory::getApplication()->enqueueMessage(
                JText::_(
                    "PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_IMAGICK_ERROR"
                ),
                "error"
            );
            return true;
        }

        $dom = new DOMDocument();
        if ($article->introtext === ""):
            return true;
        endif;
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $article->introtext);
        $all_images = $dom->getElementsByTagName("img");

        if (true) {
            //$this->params->get("ConvertAllImages") == 0) {
            $nb_converted = 0;
            $nb_moved = 0;
            $output_link = "";
            $only_moved = false;
            if (count($all_images) > 0) {
                for ($i = 0; $i < sizeof($all_images); $i++) {
                    $image_location = urldecode(
                        $all_images->item($i)->getAttribute("src")
                    );

                    $return_val = $this->convertAndDeleteImage($image_location, $nb_converted, $nb_moved, $output_link, $only_moved);

                    if ($return_val) {
                        // Replace in DOM
                        $all_images->item($i)->setAttribute("src", $output_link);
                        $all_images->item($i)->setAttribute(
                            "data-path",
                            preg_replace(
                                "/^images/",
                                "local-images:",
                                $output_link
                            )
                        );
                    }
                }

                // Remove the <html><body>
                $to_store = preg_replace(
                    "!</?body>!",
                    "",
                    $dom->saveXML($dom->documentElement->firstChild)
                );
                $article->introtext = $to_store;
            }

            $write_json = false;
            if (isset($images->image_fulltext)) {
                $image = $images->image_fulltext;
                $postfix = preg_replace("/^(.*)(#.*)$/", "\\2", $image);
                $image_path = preg_replace("/^(.*)(#.*)$/", "\\1", $image);
                if (!str_contains($image, '#')) {
                    $postfix = "";
                }
                $return_val = $this->convertAndDeleteImage($image_path, $nb_converted, $nb_moved, $output_link, $only_moved);
                if ($return_val) {
                    if (!$only_moved) {
                        $postfix = preg_replace(
                            "/(\.jpg)|(\.png)|(\.jpeg)$|(\.gif)/",
                           ".webp", $postfix);
                    }

                    $images->image_fulltext = $output_link . $postfix;
                    Factory::getApplication()->enqueueMessage(
                        "Successfully converted existing article image to webp",
                        "message"
                    );
                    $write_json = true;
                }
            }

            if (isset($images->image_intro)) {
                $image = $images->image_intro;
                $postfix = preg_replace("/^(.*)(#.*)$/", "\\2", $image);
                $image_path = preg_replace("/^(.*)(#.*)$/", "\\1", $image);
                if (!str_contains($image, '#')) {
                    $postfix = "";
                }
                $return_val = $this->convertAndDeleteImage($image_path, $nb_converted, $nb_moved, $output_link, $only_moved);
                if ($return_val) {
                    if (!$only_moved) {
                        $postfix = preg_replace(
                            "/(\.jpg)|(\.png)|(\.jpeg)$|(\.gif)/",
                            ".webp", $postfix);
                    }

                    $images->image_intro = $output_link . $postfix;
                    Factory::getApplication()->enqueueMessage(
                        "Successfully converted existing introduction image to webp",
                        "message"
                    );
                    $write_json = true;
                }
            }

            if ($write_json) {
                $article->images = json_encode($images);
            }

            if ($nb_converted == 0) {
                Factory::getApplication()->enqueueMessage(
                    //JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_NO_WEBP_DONE"),
                    "Great! All images were already in webp format",
                    "message"
                );
            } else {
                Factory::getApplication()->enqueueMessage(
                    //JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_WEBP_DONE"),
                    "{$nb_converted} image(s) successfully converted to webp",
                    "message"
                );
            }
            if ($nb_moved != 0) {
                Factory::getApplication()->enqueueMessage(
                    //JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_NO_WEBP_DONE"),
                    "{$nb_moved} images have been renamed",
                    "info"
                );
            }
        }

        if ($this->params->get("UseFirstImage") == 1) {
            if ($article->introtext === ""):
                return true;
            endif;
            if (count($all_images) > 0) {
                $src_img = urldecode($all_images->item(0)->getAttribute("src"));
                $src_alt = $all_images->item(0)->getAttribute("alt");
                $src_caption = "";
            } else {
                return true;
            }
            if (
                !isset($images->image_fulltext) or
                empty($images->image_fulltext)
            ) {
                $images->image_fulltext = $src_img;
                $images->image_fulltext_alt = $src_alt;
                Factory::getApplication()->enqueueMessage(
                    //JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_FULLTEXT_SET"),
                    "Article image has automatically been set to {$src_img}",
                    "message"
                );
            }
        } else {
            if (
                !isset($images->image_fulltext) or
                empty($images->image_fulltext)
            ) {
                return true;
            }
            $src_img = $images->image_fulltext;
        }

        // Return if intro image is already set
        if (isset($images->image_intro) and !empty($images->image_intro)) {
            Factory::getApplication()->enqueueMessage(
                JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_ALREADY_SET"),
                "notice"
            );
            return true;
        }

        $width = (int) $this->params->get("Width");
        $height = (int) $this->params->get("Height");
        $compression_level = (int) $this->params->get("ImageQuality");

        // Check plugin settings
        if (
            $compression_level < 50 or
            $compression_level > 100 or
            $width < 10 or
            $width > 2000 or
            $height < 10 or
            $height > 2000
        ) {
            Factory::getApplication()->enqueueMessage(
                JText::_(
                    "PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_SETTINGS_ERROR"
                ),
                "error"
            );
            return true;
        }

        // Create resized image
        $thumb = new Imagick(JPATH_ROOT . "/" . $src_img);

        $thumb->scaleImage(
            $this->params->get("Crop") ? 0 : $width,
            $height,
            $this->params->get("MaintainAspectRatio")
        );

        if ($this->params->get("ChangeImageQuality") == 1) {
            $thumb->setImageCompressionQuality($compression_level);
        }

        if ($this->params->get("SetProgressiveJPG") == 1) {
            $thumb->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        }

        // Get real image dimensions if maintain aspect ratio was selected
        if ($this->params->get("MaintainAspectRatio") == 1) {
            $width = $thumb->getImageWidth();
            $height = $thumb->getImageHeight();
        } elseif ($this->params->get("Crop") == 1) {
            $thumb->cropImage(
                $width,
                $height,
                ($thumb->getImageWidth() - $width) / 2,
                0
            );
        }

        // Set image intro name
        // {width} and {height} placeholders are changed to values
        $suffix = $this->params->get("Suffix");
        if (
            strpos($suffix, "{width}") !== false or
            strpos($suffix, "{height}") !== false
        ) {
            $suffix = str_replace(
                ["{width}", "{height}"],
                [$width, $height],
                $suffix
            );
        }
        $extension_pos = strrpos($src_img, ".");
        $image_with_suffix =
            substr($src_img, 0, $extension_pos) .
            $suffix .
            substr($src_img, $extension_pos);

        // Put the image in an absolute directory if said to do so
        if ($this->params->get("AbsoluteDir") == 1) {
            // Check if the subdir already exists
            $thumb_dir = JPATH_ROOT . "/" . $this->params->get("AbsDirPath");
            if (!JFolder::exists($thumb_dir)) {
                JFolder::create($thumb_dir);
            }
            $subdir_pos = strrpos($image_with_suffix, "/");
            $thumb_savepath =
                $thumb_dir . substr($image_with_suffix, $subdir_pos);
            $images->image_intro =
                $this->params->get("AbsDirPath") .
                substr($image_with_suffix, $subdir_pos);
        }
        // Put the image in a subdir if set to do so
        elseif ($this->params->get("PutInSubdir") == 1) {
            $subdir_pos = strrpos($image_with_suffix, "/");
            $images->image_intro =
                substr($image_with_suffix, 0, $subdir_pos) .
                "/" .
                $this->params->get("Subdir") .
                substr($image_with_suffix, $subdir_pos);

            // Check if the subdir already exist or create it
            $img_subdir =
                JPATH_ROOT .
                "/" .
                substr(
                    $images->image_intro,
                    0,
                    strrpos($images->image_intro, "/")
                );
            if (!JFolder::exists($img_subdir)) {
                JFolder::create($img_subdir);
            }
            $thumb_savepath = JPATH_ROOT . "/" . $images->image_intro;
        } else {
            $thumb_savepath = JPATH_ROOT . "/" . $image_with_suffix;
            $images->image_intro = $image_with_suffix;
        }

        // Copy Alt and Title fields
        if (
            $this->params->get("CopyAltTitle") == 1 and
            ($src_alt != "" or $src_altcaption != "")
        ) {
            $images->image_intro_alt = $src_alt;
            $images->image_intro_caption = $src_caption;
        }

        // Write resized image if it doesn't exist
        // and set Joomla object values
        if (!file_exists($thumb_savepath)) {
            $thumb->writeImage($thumb_savepath);
            Factory::getApplication()->enqueueMessage(
                JText::sprintf(
                    "PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_CREATED",
                    $thumb_savepath
                ),
                "message"
            );
        } else {
            Factory::getApplication()->enqueueMessage(
                JText::sprintf(
                    "PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_EXIST",
                    $thumb_savepath
                ),
                "message"
            );
        }

        $article->images = json_encode($images);
        $thumb->destroy();

        return true;
    }
}
?>
