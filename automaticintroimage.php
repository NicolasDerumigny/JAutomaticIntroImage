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

    private function isWebpAnimated($src) {
        $result = false;
        $fh = fopen($src, "rb");
        if ($fh === false) {
            Factory::getApplication()->enqueueMessage(
                "Could not open {$src}",
                "error"
            );
            return false;
        }
        fseek($fh, 12);
        if(fread($fh, 4) === 'VP8X'){
          fseek($fh, 20);
          $myByte = fread($fh, 1);
          $result = ((ord($myByte) >> 1) & 1);
        }
        fclose($fh);
        return $result;
    }


    private function convertAndDeleteImage(&$image_location, &$nb_converted, &$nb_moved, &$output_link, &$only_moved) {
        $only_moved = false;
        $output_link = $image_location;
        if (!str_starts_with($image_location, "images/")) {
            //FIXME: get parameter from media manager
            return false;
        }

        // Normalize name
        $output_link = strtolower(
            preg_replace("/[_ ]/", "-", $image_location)
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
            "/(\.jpg)|(\.png)|(\.jpeg)|(\.gif)$/",
            ".webp",
            $output_link
        );

        $output_location = preg_replace(
            "/(\.jpg)|(\.png)|(\.jpeg)|(\.gif)$/",
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
                $retval = imagewebp($image, $output_location, 80); //TODO: configure quality level
                if ($retval != true) {
                    Factory::getApplication()->enqueueMessage(
                        "Error saving webp image to {$output_location}"
                    );
                    return false;
                }
            } else {
                $output = null;
                $retval = null;
                exec(
                    "convert \"{$image_location}\" \"{$output_location}\"",
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
        $unwanted_array = array(
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'Ç' => 'c', 'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i', 'Ñ' => 'n', 'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o', 'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ý' => 'y', '€' => 'e', '$' => 'd'
        );

        $str = strtr($str,  $unwanted_array);
        $str = preg_replace("/[•,;!?:\"'><]/", "", $str);
        $str = preg_replace("/[  ]/", "-", $str); // Non-breaking space
        $str = preg_replace("/--/", "-", $str);
        return $str;
    }

    private function formatFrench($str) {
        $out = str_replace(" :", " :", $str);
        $out = str_replace(" $", " $", $out);
        $out = str_replace(" €", " €", $out);
        $out = str_replace(" !", " !", $out);
        $out = str_replace(" ?", " ?", $out);
        $out = str_replace(" </", "</", $out);
        // Twice to cover all cases
        $out = preg_replace("/([0-9]) ([0-9])/", "$1 $2", $out);
        $out = preg_replace("/([0-9]) ([0-9])/", "$1 $2", $out);
        return $out;
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

        if (file_exists(JPATH_ROOT . "/" . $file_path)) {
            // Write resized image if it doesn't exist
            // and set Joomla object values
            if (file_exists($thumb_savepath) and filemtime($thumb_savepath) > filemtime(JPATH_ROOT . "/" . $file_path)) {
                $thumb = new Imagick(JPATH_ROOT . "/" . $file_path);
                if (isset($x)):
                    $x = $thumb->getImageWidth();
                endif;
                if (isset($y)):
                    $y = $thumb->getImageHeight();
                endif;
                return true;
            }

            if ($this->isWebpAnimated(JPATH_ROOT . "/" . $file_path)) {
                copy(JPATH_ROOT . "/" . $file_path, $thumb_savepath);
                $nb_miniatures++;
                $thumb = new Imagick(JPATH_ROOT . "/" . $file_path);
                if (isset($x)):
                    $x = $thumb->getImageWidth();
                endif;
                if (isset($y)):
                    $y = $thumb->getImageHeight();
                endif;
                return true;
            }
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
            return false;
        }

        // Create resized image
        $thumb = new Imagick(JPATH_ROOT . "/" . $file_path);
        $real_width = $thumb->getImageWidth();
        $real_height = $thumb->getImageHeight();
        if ($crop):
            if ($thumb->getImageWidth() > 1.25*$thumb->getImageHeight()):
                $thumb->scaleImage(0, $width);
                $thumb->cropImage(
                    1.25*$width,
                    $width,
                    ($thumb->getImageWidth() - 1.25*$width) / 2,
                    0
                );
           else:
                $thumb->scaleImage(1.25*$width, 0);
                $thumb->cropImage(
                    $width*1.25,
                    $width,
                    0,
                    ($thumb->getImageHeight() - $width) / 2
                );
            endif;
        else:
            if ($thumb->getImageWidth() > $thumb->getImageHeight() and $real_width > $width):
                $thumb->scaleImage($width, 0);
            else:
                if ($thumb->getImageHeight() > $thumb->getImageWidth() and $real_height > $width*9/16):
                    $thumb->scaleImage(0, $width*9/16);
                endif;
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
        $thumb->writeImage($thumb_savepath);
        $nb_miniatures++;

        return true;
    }

    private function print_time($begin_time) {
        $total_time = (hrtime(true) - $begin_time)/1e+9;
        // Factory::getApplication()->enqueueMessage(
        //     "Time to convert all images: {$total_time} seconds",
        //     "message"
        // );
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
    public function onContentBeforeSave($context, &$article, $isNew, &$data)
    {
        // Remove empty lines
        if ($context == "com_engage.comment") {
            $article->body = preg_replace("/<p>\s*<\/p>/", "", $this->formatFrench($article->body));
            return true;
        }

        $begin_time = hrtime(true);
        // Check if we're saving an article
        $allowed_contexts = ["com_content.article", "com_content.form"];

        if (!in_array($context, $allowed_contexts)) {
            return true;
        }

        // Set creation date to publication date
        $article->created = $article->publish_up;

        // Auto-insert non-breaking space
        $article->title = $this->formatFrench($article->title);
        $article->metadesc = $this->formatFrench($article->metadesc);

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

        if ($category_alias == "all-content") {
            $root_cat = $categories->get();
            $cats = $root_cat->getChildren(true);
            foreach ($cats as $cat) {
                if ($cat->alias == "breves") {
                    $breves_catid = $cat->id;
                    break;
                }
            }
            $article->catid = $breves_catid;
            Factory::getApplication()->enqueueMessage(
                "Category automatically switched to \"Brèves\"",
            );

        }

        // Check for tags and set keywords to tags list
        $new_tags = [];
        $size = sizeof([$data['tags']]);
        for ($tag_id=0; $tag_id < $size; $tag_id++) {
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
            $this->print_time($begin_time);
            return false;
        } else {
            $article->metakey = $tags;
            Factory::getApplication()->enqueueMessage(
                "Keywords sucessfully set to \"{$article->metakey}\"",
                "message"
            );
        }

        // Remove special charactures from alias
        if ($this->stripAccents($article->alias) != $article->alias) {
            $article->alias = $this->stripAccents($article->alias);
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
            $this->print_time($begin_time);
            return false;
        }

        $check_timestamp = hrtime(true);
        $checks_time = ($check_timestamp - $begin_time)/1e+9;
        // Factory::getApplication()->enqueueMessage(
        //     "Time to perform all checks: {$checks_time} seconds",
        //     "message"
        // );


        // Create thumb directory
        if ($this->params->get("AbsoluteDir") == 1) {
            $thumb_dir = JPATH_ROOT . "/" . $this->params->get("AbsDirPath");
            if (!JFolder::exists($thumb_dir)) {
                JFolder::create($thumb_dir);
            }
        }

        // Convert all images to webp
        $dom = new DOMDocument("1.0", "utf-8");
        if ($article->introtext === ""):
            $this->print_time($begin_time);
            return true;
        endif;
        $article->introtext = str_replace("<![CDATA[ ]]>", "", $article->introtext);
        $article->introtext = str_replace("></", "> </", $article->introtext);
        $article->introtext = str_replace(" <img", "<img", $article->introtext);
        $article->introtext = $this->formatFrench($article->introtext);
        $article->introtext = preg_replace(
            '/<span class="mce-nbsp-wrap" contenteditable="false">[\s]*</',
            '<span class="mce-nbsp-wrap" contenteditable="false"> <',
            $article->introtext);
        $dom->loadXML('<div id="parsing-wrapper">' . $article->introtext . '</div>');
        $paragraphs = $dom->getElementsByTagName('p');
        for ($i=0; $i < $paragraphs->length; $i++) {
            $p = $paragraphs->item($i);
            $class_name = $p->getAttribute("class");
            if ((str_contains($class_name, "lightbox") || str_contains($class_name, "insert_multiple_images") || str_contains($class_name, "comparaison-images")) && !(str_contains($class_name, "insert_image_"))) {
                $p->setAttribute("class", str_replace("legende", "", $class_name));
                $new_content = strip_tags($p->C14N(), ["<p>", "<img>", "<a>", "<iframe>"]);
                $fragment = $dom->createDocumentFragment();
                $tmp_dom = new DOMDocument("1.0", "utf-8");
                $tmp_dom->loadXML($new_content);
                $new_node = $tmp_dom->firstElementChild;
                $new_node = $dom->importNode($new_node, true);
                $p->parentNode->replaceChild($new_node, $p);
            }
        }
        $all_images = $dom->getElementsByTagName("img");

        $loadhtml_timestamp = hrtime(true);
        $loadhtml_time = ($loadhtml_timestamp - $check_timestamp)/1e+9;
        // Factory::getApplication()->enqueueMessage(
        //     "Time to parse content: {$loadhtml_time} seconds",
        //     "message"
        // );

        // Convert and create thumbnails
        if (true) {
            //$this->params->get("ConvertAllImages") == 0) {
            $nb_converted = 0;
            $nb_moved = 0;
            $output_link = "";
            $only_moved = false;
            $nb_miniatures = 0;
            if (count($all_images) > 0) {
                $size = sizeof($all_images);
                for ($i = 0; $i < $size; $i++) {
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

                // Remove the wrapper
                $to_store = "";
                foreach ($dom->documentElement->childNodes as $node) {
                    $to_store .= $dom->saveXML($node);
                }
                $article->introtext = $to_store;
            }

            $convert_timestamp = hrtime(true);
            $convert_time = ($convert_timestamp - $loadhtml_timestamp)/1e+9;
            // Factory::getApplication()->enqueueMessage(
            //     "Time to convert all images: {$convert_time} seconds",
            //     "message"
            // );

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
                        "/(\.jpg)|(\.png)|(\.jpeg)|(\.gif)$/",
                       ".webp", $postfix);

                    $images->image_fulltext = $output_link . $postfix;
                    Factory::getApplication()->enqueueMessage(
                        "successfully converted existing article image to webp",
                        "message"
                    );
                    $write_json = true;
                }
            }

            $fulltext_timestamp = hrtime(true);
            $fulltext_time = ($fulltext_timestamp - $convert_timestamp)/1e+9;
            // Factory::getApplication()->enqueueMessage(
            //     "Time to convert fulltext image: {$fulltext_time} seconds",
            //     "message"
            // );

            // If an intro image exists, convert it to a thumb and set it if this was not already the case
            if (isset($images->image_intro) and $images->image_intro !== '') {
                $image = $images->image_intro;
                $image_path = preg_replace("/^(.*)(#.*)$/", "\\1", $image);
                if (!str_contains($image, "_thumb.webp")) {
                    $this->convertAndDeleteImage($image_path, $nb_converted, $nb_moved, $output_link, $only_moved);
                    $x = 0;
                    $y = 0;
                    $this->resizeWebp($output_link, 450, "_thumb", $nb_miniatures, $x, $y, true);
                    // TODO: may not be set
                    $output_link = "/" . $this->params->get("AbsDirPath") . "/" .
                        preg_replace(
                            "/\.webp/",
                            "_thumb.webp",
                            basename($output_link));

                    $images->image_intro = $output_link;
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
                $this->print_time($begin_time);
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
                    $this->print_time($begin_time);
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
                $this->print_time($begin_time);
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
            $this->print_time($begin_time);
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
        $src_img = preg_replace("/\.webp$/", "_thumb.webp", $src_img);
        // FIXME what about if not set?
        $thumb_dir = $this->params->get("AbsDirPath");
        $subdir_pos = strrpos($src_img, "/");
        $src_img = $thumb_dir . substr($src_img, $subdir_pos);
        Factory::getApplication()->enqueueMessage(
            //JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_FULLTEXT_SET"),
            "Intro image has automatically been set to {$src_img}",
            "message"
        );

        $intro_timestamp = hrtime(true);
        $intro_time = ($intro_timestamp - $fulltext_timestamp)/1e+9;
        // Factory::getApplication()->enqueueMessage(
        //     "Time to resize intro image: {$intro_time} seconds",
        //     "message"
        // );

        $images->image_intro = $src_img;
        $images->image_intro_alt = $src_alt;
        $article->images = json_encode($images);
        $this->print_time($begin_time);
        return true;
    }

    // Forward options to TinyMCE
    public function onBeforeRender() {
        $input = Factory::getApplication()->input;
        $doc = Factory::getDocument();
        $editorOptions = $doc->getScriptOptions('plg_editor_tinymce');

        if(empty($editorOptions['tinyMCE'])) {
            return;
        }

        if (!(($input->get("view") == "form" or ($input->get("option") == "com_content" and $input->get("view") == "article")) and $input->get("layout") == "edit")) {
            // No popup menu
            $editorOptions['tinyMCE']['default']['quickbars_selection_toolbar'] = '';
            // Custom injector, "e" is the argument name
            $editorOptions['tinyMCE']['default']['init_instance_callback'] = 'e.getContainer().getElementsByClassName("tox-statusbar__wordcount")[0].click();';
            $editorOptions['tinyMCE']['default']['setup'] = 'e.on("WordCountUpdate", () => {' .
                'let wc = e.getContainer().getElementsByClassName("tox-statusbar__wordcount")[0];' .
                'if (tinymce.activeEditor.plugins.wordcount.body.getCharacterCount() > 4000) {wc.classList.add("over-limit")} else {wc.classList.remove("over-limit");}' .
              '});';
        } else {
            $editorOptions['tinyMCE']['default']['quickbars_selection_toolbar'] = 'bold italic underline strikethrough | subscript superscript | link | h2 h3 h4 blockquote | tablemergecells';
        }

        // Additional options for the article editor
        $editorOptions['tinyMCE']['default']['default_link_target'] = '_blank';
        $editorOptions['tinyMCE']['default']['paste_as_text'] = 'true';
        $editorOptions['tinyMCE']['default']['table_header_type'] = 'cells';
        $editorOptions['tinyMCE']['default']['table_sizing_mode'] = 'responsive';
        $editorOptions['tinyMCE']['default']['table_resize_bars'] = 'false';
        $editorOptions['tinyMCE']['default']['object_resizing'] = 'img';
        $editorOptions['tinyMCE']['default']['quickbars_insert_toolbar'] = '';
        $editorOptions['tinyMCE']['default']['table_toolbar'] = 'tableprops tabledelete | tablerowheader tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol';
        $doc->addScriptOptions('plg_editor_tinymce', $editorOptions);
    }
}
?>
