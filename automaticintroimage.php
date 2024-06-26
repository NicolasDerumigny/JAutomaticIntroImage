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
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Filesystem\Folder;

class plgContentAutomaticIntroImage extends CMSPlugin
{
    /**
     * Load the language file on instantiation. Note this is only available in Joomla 3.1 and higher.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    private $timestampMin = 1706351104;

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


    private function convertAndDeleteImage($image_url, &$nb_converted, &$nb_moved, &$output_link, &$only_moved) {
        $only_moved = false;
        if (!str_starts_with($image_url, "images/")) {
            //FIXME: get parameter from media manager
            return false;
        }

        // Normalize name
        $output_link = strtolower(
            preg_replace("/[_ ]/", "-", $image_url)
        );
        $image_location = JPATH_ROOT . "/" . $image_url;
        $output_location = JPATH_ROOT . "/" . $output_link;

        $folder = dirname($output_location);

        if (!Folder::exists($folder)) {
            Folder::create($folder);
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
                // Original -> webp image quality level is 95
                $retval = imagewebp($image, $output_location, 95);
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
        $str = preg_replace("/[  ]/", "-", $str); // Non-breaking spaces
        $str = preg_replace("/-+/", "-", $str);
        $str = preg_replace("/-*$/", "", $str);
        return $str;
    }

    private function formatFrench($str) {
        $out = str_replace(" :", " :", $str);
        $out = str_replace(" $", " $", $out);
        $out = str_replace(" €", " €", $out);
        $out = str_replace(" !", " !", $out);
        $out = str_replace(" ?", " ?", $out);
        // Twice to cover all cases
        $out = preg_replace("/([0-9]) ([0-9])/", "$1 $2", $out);
        $out = preg_replace("/([0-9]) ([0-9])/", "$1 $2", $out);
        return $out;
    }

    // Convert an input webp image to a webp image with same proportion with width `width`
    // and suffix `suffix` (before extension). If the filename already contains the suffix,
    // do nothing
    // Fails in crop mode if the size is not sufficient. Copy the file in non-crop
    // mode if the size is not sufficient
    private function resizeWebp($file_path, $dimensions, $suffix, &$nb_miniatures, $crop = false, $quality=80, $force_resize = false) {
        if (is_array($dimensions)) {
            $width = $dimensions[0];
            $height = $dimensions[1];
        } else {
            $width = $dimensions;
            $height = $dimensions;
        }
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

        // Put the image in an absolute directory
        $thumb_dir = JPATH_ROOT . "/" . $this->params->get("AbsDirPath");
        $subdir_pos = strrpos($image_with_suffix, "/");
        $thumb_savepath = $thumb_dir . substr($image_with_suffix, $subdir_pos);

        if (file_exists(JPATH_ROOT . "/" . $file_path)) {
            // Write resized image if it doesn't exist
            // and set Joomla object values

            // If it exist, and the modification date is after the original file
            // and after the new portrait update
            if (file_exists($thumb_savepath) and filemtime($thumb_savepath) > filemtime(JPATH_ROOT . "/" . $file_path) and filemtime($thumb_savepath) > $this->timestampMin) {
                $thumb = new Imagick(JPATH_ROOT . "/" . $file_path);
                return true;
            }

            if ($this->isWebpAnimated(JPATH_ROOT . "/" . $file_path)) {
                copy(JPATH_ROOT . "/" . $file_path, $thumb_savepath);
                $nb_miniatures++;
                $thumb = new Imagick(JPATH_ROOT . "/" . $file_path);
                return true;
            }
        }

        // Create resized image
        $thumb = new Imagick(JPATH_ROOT . "/" . $file_path);
        $real_width = $thumb->getImageWidth();
        $real_height = $thumb->getImageHeight();
        if ($crop) {
            if ((!$force_resize) && ($real_width < $width or $real_height < $height)) {
                return false;
            }
            if ($thumb->getImageWidth()/$thumb->getImageHeight() >= $width/$height) {
                $thumb->scaleImage(0, $height);
                $thumb->cropImage(
                    $width,
                    $height,
                    ($thumb->getImageWidth() - $width) / 2,
                    0
                );
            } else {
                $thumb->scaleImage($width, 0);
                $thumb->cropImage(
                    $width,
                    $height,
                    0,
                    ($thumb->getImageHeight() - $height) / 2
                );
            }
        } else {
            // Nearly square images
            if ($thumb->getImageWidth() >= 0.9*$thumb->getImageHeight() and $real_width > $width) {
                $thumb->scaleImage($width, 0);
            } else {
                // Resize portrait images for mobile:
                // - images may take the whole screen (i.e. up to ~1000 px height)
                // - "big" images (displayed on FHD landscape) will not span up more that the screen
                // height (landscape !)
                // Therefore, sub-1000px desireds height is scaled to 2*width, else to $resezied_height
                $resized_height = min(2*$width, 1000);
                if ($thumb->getImageHeight() > $thumb->getImageWidth() and $real_height > $resized_height) {
                    $thumb->scaleImage(0, $resized_height);
                }
            }
        }
        $thumb->setImageCompressionQuality($quality);
        $thumb->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        $thumb->writeImage($thumb_savepath);
        $nb_miniatures++;

        return true;
    }

    private function printTime($begin_time) {
        $total_time = (hrtime(true) - $begin_time)/1e+9;
        // Factory::getApplication()->enqueueMessage(
        //     "Time to convert all images: {$total_time} seconds",
        //     "message"
        // );
    }

    private function printConvertMessages($nb_converted, $nb_moved, $nb_miniatures) {
        if ($nb_converted == 0) {
            Factory::getApplication()->enqueueMessage(
                //Text::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_NO_WEBP_DONE"),
                "Great! All images were already in webp format",
                "message"
            );
        } else {
            Factory::getApplication()->enqueueMessage(
                //Text::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_WEBP_DONE"),
                "{$nb_converted} image(s) successfully converted to webp",
                "message"
            );
        }
        if ($nb_moved != 0) {
            Factory::getApplication()->enqueueMessage(
                //Text::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_NO_WEBP_DONE"),
                "{$nb_moved} images have been renamed",
                "info"
            );
        }
        if ($nb_miniatures != 0) {
            Factory::getApplication()->enqueueMessage(
                //Text::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_NO_WEBP_DONE"),
                "{$nb_miniatures} images have been converted for lower resolutions",
                "info"
            );
        }
    }

    private function createAllThumbnails($image_location, &$nb_miniatures) {
        $this->resizeWebp($image_location, 1920, "_fhd", $nb_miniatures, false, 90);
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
            $this->printTime($begin_time);
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
                Text::_(
                    "PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_IMAGICK_ERROR"
                ),
                "error"
            );
            $this->printTime($begin_time);
            return false;
        }

        $check_timestamp = hrtime(true);
        $checks_time = ($check_timestamp - $begin_time)/1e+9;
        // Factory::getApplication()->enqueueMessage(
        //     "Time to perform all checks: {$checks_time} seconds",
        //     "message"
        // );


        // Create thumb directory
        $thumb_dir = JPATH_ROOT . "/" . $this->params->get("AbsDirPath");
        if (!Folder::exists($thumb_dir)) {
            Folder::create($thumb_dir);
        }

        // Convert all images to webp
        $dom = new DOMDocument("1.0", "utf-8");
        if ($article->introtext === "") {
            $this->printTime($begin_time);
            return true;
        }
        $article->introtext = $this->formatFrench($article->introtext);
        $article->introtext = preg_replace(
            '/<span class="mce-nbsp-wrap" contenteditable="false">[\s]*</',
            '<span class="mce-nbsp-wrap" contenteditable="false"> <',
            $article->introtext);
        libxml_use_internal_errors(true);
        $dom->loadHTML('<meta charset="utf8">' . $article->introtext, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();
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
                $to_store .= $dom->saveHTML($node);
            }
            $article->introtext = $to_store;
        }

        $convert_timestamp = hrtime(true);
        $convert_time = ($convert_timestamp - $loadhtml_timestamp)/1e+9;
        // Factory::getApplication()->enqueueMessage(
        //     "Time to convert all images: {$convert_time} seconds",
        //     "message"
        // );

        // If a fulltext image exists, convert it to webp and create replicas
        $image_fulltext_written = false;
        if (isset($images->image_fulltext) and $images->image_fulltext !== '') {
            $image = $images->image_fulltext;
            $postfix = urldecode(preg_replace("/^(.*)(#.*)$/", "\\2", $image));
            $image_path = urldecode(preg_replace("/^(.*)(#.*)$/", "\\1", $image));
            if (!str_contains($image, '#')) {
                $postfix = "";
            }
            $output_link = "";
            $return_val = $this->convertAndDeleteImage($image_path, $nb_converted, $nb_moved, $output_link, $only_moved);
            $this->createAllThumbnails($output_link, $nb_miniatures);
            // Fulltext -> Discover miniature too
            $this->resizeWebp($output_link, [1500, 850], "_preview", $nb_miniatures, true);
            // If pinned, then the "_thumb" image is also needed
            $this->resizeWebp($output_link, [560, 450], "_thumb", $nb_miniatures, true, 80, true);

            if ($return_val) {
                $postfix = str_replace(
                    $image_path,
                    $output_link,
                    $postfix);

                $images->image_fulltext = $output_link . $postfix;
                Factory::getApplication()->enqueueMessage(
                    "successfully converted existing article image to webp",
                    "message"
                );
            }
            $image_fulltext_written = true;
        }

        $fulltext_timestamp = hrtime(true);
        $fulltext_time = ($fulltext_timestamp - $convert_timestamp)/1e+9;
        // Factory::getApplication()->enqueueMessage(
        //     "Time to convert fulltext image: {$fulltext_time} seconds",
        //     "message"
        // );

        // If an intro image exists, convert it to a thumb if this was not already the case
        if (isset($images->image_intro) and $images->image_intro !== '') {
            $image = $images->image_intro;
            $image_path = urldecode(preg_replace("/^(.*)(#.*)$/", "\\1", $image));
            if (!str_contains($image, "_thumb.webp")) {
                $this->convertAndDeleteImage($image_path, $nb_converted, $nb_moved, $output_link, $only_moved);
                // No need for further conversion for intro images (only thumb is used)
                $this->resizeWebp($output_link, [560, 450], "_thumb", $nb_miniatures, true, 80, true);
                $output_link = "/" . $this->params->get("AbsDirPath") . "/" .
                    preg_replace(
                        "/\.webp/",
                        "_thumb.webp",
                        basename($output_link));

                $images->image_intro = $output_link;
                Factory::getApplication()->enqueueMessage(
                    "Successfully converted and cropped existing introduction image to webp",
                    "message"
                );
            }
        }

        // Write converted fulltext/intro image
        $article->images = json_encode($images);

        // Use fulltext, if existing
        if ((isset($images->image_fulltext)) and $images->image_fulltext !== '') {
            $src_img = $images->image_fulltext;
            $src_alt = $images->image_fulltext_alt;
            $src_img = preg_replace("/#.*$/", "", $src_img);
        // else, use the first image, if existing
        } else if (count($all_images) > 0) {
            $src_img = urldecode($all_images->item(0)->getAttribute("src"));
            $src_img = preg_replace("/#.*$/", "", $src_img);
            $src_alt = $all_images->item(0)->getAttribute("alt");
            // Set also the fulltext image to the src image, if not set before
            if (
                (!isset($images->image_fulltext)) or
                $images->image_fulltext === ''
            ) {
                $images->image_fulltext = $src_img;
                $images->image_fulltext_alt = $src_alt;
                $this->resizeWebp($src_img, [1500, 850], "_preview", $nb_converted, true);
                Factory::getApplication()->enqueueMessage(
                    "Article image has automatically been set to {$src_img}",
                    "message"
                );
            }
            $article->images = json_encode($images);
        // Else, give up
        } else {
            $this->printConvertMessages($nb_converted, $nb_moved, $nb_miniatures);
            $this->printTime($begin_time);
            return true;
        }

        $this->printConvertMessages($nb_converted, $nb_moved, $nb_miniatures);

        // Return if intro image is already set
        if (isset($images->image_intro) and $images->image_intro !== '') {
            Factory::getApplication()->enqueueMessage(
                Text::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_ALREADY_SET"),
                "notice"
            );
            $this->printTime($begin_time);
            return true;
        }
        // Else, also resize it
        $nb_miniatures = 0;
        $this->resizeWebp($src_img, [560, 450], "_thumb", $nb_miniatures, true, 80, true);
        if ($nb_miniatures) {
            Factory::getApplication()->enqueueMessage(
                "Square miniature successfully cropped",
                "message"
           );
        }
        $src_img = preg_replace("/\.webp$/", "_thumb.webp", $src_img);
        $thumb_dir = $this->params->get("AbsDirPath");
        $subdir_pos = strrpos($src_img, "/");
        $src_img = $thumb_dir . substr($src_img, $subdir_pos);
        Factory::getApplication()->enqueueMessage(
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
        $this->printTime($begin_time);
        return true;
    }
}
?>
