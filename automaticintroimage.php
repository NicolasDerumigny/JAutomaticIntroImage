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

    private function convertAndDeleteImage(&$image_location, &$nb_converted, &$nb_moved, &$output_link, &$only_moved) {
        $only_moved = false;
        $output_link = $image_location;
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

        if (!JFolder::exists($folder)) {
            JFolder::create($folder);
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

    private function stripAccents($str) {
        $str = preg_replace("/•/", "", $str);
        $str = preg_replace("/--/", "-", $str);
        return strtr(utf8_decode($str), utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ€$'), 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUYed');
    }

    // Convert an input webp image to a webp image with same proportion with width `width`
    // and suffix `suffix` (before extension). If the filename already contains the suffix,
    // do nothing
    private function resizeWebp($file_path, $width, $suffix, &$nb_miniatures, &$x = null, &$y = null, $crop = false) {
        if (str_contains($file_path, $suffix . ".webp")) {
            return false;
        }

        if (!str_starts_with($file_path, "images/") && !str_starts_with($file_path, "/images/")) {
            //FIXME: get parameter from media manager
            return false;
        }

        $compression_level = (int) $this->params->get("ImageQuality");
        if (
            $compression_level < 50 or
            $compression_level > 100
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
        $thumb = new Imagick(JPATH_ROOT . "/" . $file_path);
        if ($crop):
            if ($thumb->getImageWidth() > $thumb->getImageHeight()):
                $thumb->scaleImage(0, $width);
                $thumb->cropImage(
                    $width,
                    $width,
                    ($thumb->getImageWidth() - $width) / 2,
                    0
                );
           else:
                $thumb->scaleImage($width, 0);
                $thumb->cropImage(
                    $width,
                    $width,
                    0,
                    ($thumb->getImageHeight() - $width) / 2
                );
            endif;
        else:
            if ($thumb->getImageWidth() > $thumb->getImageHeight()):
                $thumb->scaleImage($width, 0);
            else:
                $thumb->scaleImage(0, $width*9/16);
            endif;
        endif;
        if (isset($x)):
            $x = $thumb->getImageWidth();
        endif;
        if (isset($y)):
            $y = $thumb->getImageHeight();
        endif;
        $thumb->setImageCompressionQuality($compression_level);
        $thumb->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        $extension_pos = strrpos($file_path, ".");
        $image_with_suffix =
            substr($file_path, 0, $extension_pos) .
            $suffix .
            substr($file_path, $extension_pos);

        // Put the image in an absolute directory if said to do so
        if ($this->params->get("AbsoluteDir") == 1) {
            $thumb_dir = JPATH_ROOT . "/" . $this->params->get("AbsDirPath");
            $subdir_pos = strrpos($image_with_suffix, "/");
            $thumb_savepath =
                $thumb_dir . substr($image_with_suffix, $subdir_pos);
        }
        // Put the image in a subdir if set to do so
        elseif ($this->params->get("PutInSubdir") == 1) {
            $subdir_pos = strrpos($image_with_suffix, "/");
            $save_rel_location =
                substr($image_with_suffix, 0, $subdir_pos) .
                "/" .
                $this->params->get("Subdir") .
                substr($image_with_suffix, $subdir_pos);

            // Check if the subdir already exist or create it
            $img_subdir =
                JPATH_ROOT .
                "/" .
                substr(
                    $image_with_suffix,
                    0,
                    strrpos($image_with_suffix, "/")
                );
            if (!JFolder::exists($img_subdir)) {
                JFolder::create($img_subdir);
            }
            $thumb_savepath = JPATH_ROOT . "/" . $save_rel_location;
        } else {
            $thumb_savepath = JPATH_ROOT . "/" . $image_with_suffix;
        }

        // Write resized image if it doesn't exist
        // and set Joomla object values
        if (!file_exists($thumb_savepath)) {
            $thumb->writeImage($thumb_savepath);
            $nb_miniatures++;
        }

        return true;
    }

    private function createAllThumbnails($image_location, &$nb_miniatures) {
        $this->resizeWebp($image_location, 1920, "_fhd", $nb_miniatures);
        $this->resizeWebp($image_location, 1280, "_sd", $nb_miniatures);
        $this->resizeWebp($image_location, 450, "_mini", $nb_miniatures);
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
    public function onContentBeforeSave($context, $article, $isNew, $data)
    {
        // Check if we're saving an article
        $allowed_contexts = ["com_content.article", "com_content.form"];

        if (!in_array($context, $allowed_contexts)) {
            return true;
        }

        // Set creation date to publication date
        $article->created = $article->publish_up;

        // Check for meta description
        if ($article->metadesc == "") {
            $article->setError("No meta description!");
            return false;
        }

        // Forbid certain categories
        $categories = Categories::getInstance("Content", []);
        $category_alias = $categories->get($article->catid)->alias;
        if ($category_alias == "actualites" || $category_alias == "dossiers") {
            $article->setError(
                "Invalid category (\"Dossiers\" or \"Actualités\")"
            );
            return false;
        }

        // Check for tags and set keywords to tags list
        $new_tags = [];
        for ($tag_id=0; $tag_id < sizeof($data['tags']); $tag_id++) {
            $tag = $data['tags'][$tag_id];
            if (str_starts_with($tag, "#new#")) {
                array_push($new_tags, substr($tag, 5));
            }
        }
        $tagsHelper = new TagsHelper();
        $tags = join(
            ", ",
            array_merge(
                $tagsHelper->getTagNames($article->newTags),
                $new_tags)
        );
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

        // Remove special charactures from alias
        $article->alias = $this->stripAccents($article->alias);

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

        // Create thumb directory
        if ($this->params->get("AbsoluteDir") == 1) {
            $thumb_dir = JPATH_ROOT . "/" . $this->params->get("AbsDirPath");
            if (!JFolder::exists($thumb_dir)) {
                JFolder::create($thumb_dir);
            }
        }

        // Convert all images to webp
        $dom = new DOMDocument();
        if ($article->introtext === ""):
            return true;
        endif;
        $article->introtext = preg_replace("/><\//", "> </", $article->introtext);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $article->introtext);
        $all_images = $dom->getElementsByTagName("img");

        // Convert and create thumbnails
        if (true) {
            //$this->params->get("ConvertAllImages") == 0) {
            $nb_converted = 0;
            $nb_moved = 0;
            $output_link = "";
            $only_moved = false;
            $nb_miniatures = 0;
            if (count($all_images) > 0) {
                for ($i = 0; $i < sizeof($all_images); $i++) {
                    $image_location = urldecode(
                        $all_images->item($i)->getAttribute("src")
                    );

                    $return_val = $this->convertAndDeleteImage(
                        $image_location,
                        $nb_converted,
                        $nb_moved,
                        $output_link,
                        $only_moved);
                    $this->createAllThumbnails($output_link, $nb_miniatures);

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

            // If a fulltext image exists, create thumbnails but do not modify
            $write_json = false;
            if (isset($images->image_fulltext) and $images->image_fulltext !== '') {
                $image = $images->image_fulltext;
                $postfix = preg_replace("/^(.*)(#.*)$/", "\\2", $image);
                $image_path = preg_replace("/^(.*)(#.*)$/", "\\1", $image);
                if (!str_contains($image, '#')) {
                    $postfix = "";
                }
                $output_link = "";
                $return_val = $this->convertAndDeleteImage($image_path, $nb_converted, $nb_moved, $output_link, $only_moved);
                $this->createAllThumbnails($output_link, $nb_miniatures);

                if ($return_val) {
                    $postfix = preg_replace(
                        "/(\.jpg)|(\.png)|(\.jpeg)$|(\.gif)/",
                       ".webp", $postfix);

                    $images->image_fulltext = $output_link . $postfix;
                    factory::getapplication()->enqueuemessage(
                        "successfully converted existing article image to webp",
                        "message"
                    );
                    $write_json = true;
                }
            }

            // If an intro image exists, convert it to a thumb and set it if this was not already the case
            if (isset($images->image_intro) and $images->image_intro !== '') {
                $image = $images->image_intro;
                $postfix = preg_replace("/^(.*)(#.*)$/", "\\2", $image);
                $image_path = preg_replace("/^(.*)(#.*)$/", "\\1", $image);
                if (!str_contains($image, '#')) {
                    $postfix = "";
                }
                if (!str_contains($image, "_thumb.webp")) {
                    $this->convertAndDeleteImage($image_path, $nb_converted, $nb_moved, $output_link, $only_moved);
                    $x = 0;
                    $y = 0;
                    $this->resizeWebp($output_link, 450, "_thumb", $nb_miniatures, $x, $y, true);
                    //FIXME: may be not set
                    $output_link = "/" . $this->params->get("AbsDirPath") . "/" .
                        preg_replace(
                            "/\.webp/",
                            "_thumb.webp",
                            basename($image_path));
                    $postfix = "#joomlaImage://local-image/" .
                            $output_link . "/?width={$x}&height={$y}";
                    $postfix = preg_replace(
                        "/(\.jpg)|(\.png)|(\.jpeg)$|(\.gif)/",
                        ".webp", $postfix);

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
            if ($nb_miniatures != 0) {
                Factory::getApplication()->enqueueMessage(
                    //JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_NO_WEBP_DONE"),
                    "{$nb_miniatures} images have been converted for lower resolutions",
                    "info"
                );
            }
        }

        if ($this->params->get("UseFirstImage") == 1) {
            if ($article->introtext === "") {
                return true;
            }
            if (count($all_images) > 0) {
                $src_img = urldecode($all_images->item(0)->getAttribute("src"));
                $src_alt = $all_images->item(0)->getAttribute("alt");
            }
            if (
                (!isset($images->image_fulltext)) or
                $images->image_fulltext === ''
            ) {
                if (count($all_images) === 0) {
                    return true;
                }
                $images->image_fulltext = $src_img;
                $images->image_fulltext_alt = $src_alt;
                Factory::getApplication()->enqueueMessage(
                    //JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_FULLTEXT_SET"),
                    "Article image has automatically been set to {$src_img}",
                    "message"
                );
                $article->images = json_encode($images);
            } else {
                // We know the webp thumb exists as it has alredy been converted right before
                $src_img = $images->image_fulltext;
                $src_alt = $images->image_fulltext_alt;
            }
        } else {
            if (
                !isset($images->image_fulltext) or
                $images->image_fulltext === ''
            ) {
                return true;
            }
            $src_img = $images->image_fulltext;
            $src_alt = $images->image_fulltext_alt;
        }

        // Return if intro image is already set
        if (isset($images->image_intro) and $images->image_intro !== '') {
            Factory::getApplication()->enqueueMessage(
                JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_ALREADY_SET"),
                "notice"
            );
            return true;
        }

        $nb_miniatures = 0;
        $src_img = preg_replace("/#.*$/", "", $src_img);
        $this->resizeWebp($src_img, 450, "_thumb", $nb_miniatures, $x, $y, true);
        if ($nb_miniatures) {
            Factory::getApplication()->enqueueMessage(
                "Square miniature successfully cropped",
                "message"
           );
        }
        $src_img = preg_replace("/.webp$/", "_thumb.webp", $src_img);
        // FIXME what about if not set?
        $thumb_dir = $this->params->get("AbsDirPath");
        $subdir_pos = strrpos($src_img, "/");
        $src_img = $thumb_dir . substr($src_img, $subdir_pos);
        Factory::getApplication()->enqueueMessage(
            //JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_FULLTEXT_SET"),
            "Intro image has automatically been set to {$src_img}",
            "message"
        );

        $images->image_intro = $src_img;
        $images->image_intro_alt = $src_alt;
        $article->images = json_encode($images);

        return true;
    }

    // Forward options to TinyMCE
    public function onBeforeRender() {
        $doc = JFactory::getDocument();
        $editorOptions = $doc->getScriptOptions('plg_editor_tinymce');

        if(empty($editorOptions['tinyMCE']))
        {
            return;
        }

        // Always open in new tab
        $editorOptions['tinyMCE']['default']['default_link_target'] = '_blank';
        // No popup menu
        $editorOptions['tinyMCE']['default']['quickbars_insert_toolbar'] = '';
        $editorOptions['tinyMCE']['default']['quickbars_selection_toolbar'] = '';

        $doc->addScriptOptions('plg_editor_tinymce', $editorOptions);
    }
}
?>
