<?php
/**
 * @package dompdf
 * @link    http://dompdf.github.com/
 * @author  Benj Carson <benjcarson@digitaljunkies.ca>
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\Renderer;

use Dompdf\Frame;
use Dompdf\Helpers;

/**
 * Renders inline frames
 *
 * @access  private
 * @package dompdf
 */
class Inline extends AbstractRenderer
{
    function render(Frame $frame)
    {
        if (!$frame->get_first_child()) {
            return; // No children, no service
        }

        $style = $frame->get_style();
        $dompdf = $this->_dompdf;

        // Draw the left border if applicable
        $bp = $style->get_border_properties();
        $widths = [
            (float)$style->length_in_pt($bp["top"]["width"]),
            (float)$style->length_in_pt($bp["right"]["width"]),
            (float)$style->length_in_pt($bp["bottom"]["width"]),
            (float)$style->length_in_pt($bp["left"]["width"])
        ];

        // Draw the background & border behind each child.  To do this we need
        // to figure out just how much space each child takes:
        list($x, $y) = $frame->get_first_child()->get_position();

        $this->_set_opacity($frame->get_opacity($style->opacity));

        $do_debug_layout_line = $dompdf->getOptions()->getDebugLayout()
            && $dompdf->getOptions()->getDebugLayoutInline();

        list($w, $h) = $this->get_child_size($frame, $do_debug_layout_line);

        // make sure the border and background start inside the left margin
        $left_margin = (float)$style->length_in_pt($style->margin_left);
        $x += $left_margin;

        // Handle the last child
        if (($bg = $style->background_color) !== "transparent") {
            $this->_canvas->filled_rectangle($x + $widths[3], $y + $widths[0], $w, $h, $bg);
        }

        //On continuation lines (after line break) of inline elements, the style got copied.
        //But a non repeatable background image should not be repeated on the next line.
        //But removing the background image above has never an effect, and removing it below
        //removes it always, even on the initial line.
        //Need to handle it elsewhere, e.g. on certain ...clone()... usages.
        // Repeat not given: default is Style::__construct
        // ... && (!($repeat = $style->background_repeat) || $repeat === "repeat" ...
        //different position? $this->_background_image($url, $x, $y, $w, $h, $style);
        if (($url = $style->background_image) && $url !== "none") {
            $this->_background_image($url, $x + $widths[3], $y + $widths[0], $w, $h, $style);
        }

        // Add the border widths
        $w += (float)$widths[1] + (float)$widths[3];
        $h += (float)$widths[0] + (float)$widths[2];

        // If this is the first row, draw the left border too
        if ($bp["left"]["style"] !== "none" && $bp["left"]["color"] !== "transparent" && $widths[3] > 0) {
            $method = "_border_" . $bp["left"]["style"];
            $this->$method($x, $y, $h, $bp["left"]["color"], $widths, "left");
        }

        // Draw the top & bottom borders
        if ($bp["top"]["style"] !== "none" && $bp["top"]["color"] !== "transparent" && $widths[0] > 0) {
            $method = "_border_" . $bp["top"]["style"];
            $this->$method($x, $y, $w, $bp["top"]["color"], $widths, "top");
        }

        if ($bp["bottom"]["style"] !== "none" && $bp["bottom"]["color"] !== "transparent" && $widths[2] > 0) {
            $method = "_border_" . $bp["bottom"]["style"];
            $this->$method($x, $y + $h, $w, $bp["bottom"]["color"], $widths, "bottom");
        }

        // Helpers::var_dump(get_class($frame->get_next_sibling()));
        // $last_row = get_class($frame->get_next_sibling()) !== 'Inline';
        // Draw the right border if this is the last row
        if ($bp["right"]["style"] !== "none" && $bp["right"]["color"] !== "transparent" && $widths[1] > 0) {
            $method = "_border_" . $bp["right"]["style"];
            $this->$method($x + $w, $y, $h, $bp["right"]["color"], $widths, "right");
        }

        $node = $frame->get_node();
        $id = $node->getAttribute("id");
        if (strlen($id) > 0) {
            $this->_canvas->add_named_dest($id);
        }

        // Only two levels of links frames
        $is_link_node = $node->nodeName === "a";
        if ($is_link_node) {
            if (($name = $node->getAttribute("name"))) {
                $this->_canvas->add_named_dest($name);
            }
        }

        if ($frame->get_parent() && $frame->get_parent()->get_node()->nodeName === "a") {
            $link_node = $frame->get_parent()->get_node();
        }

        // Handle anchors & links
        if ($is_link_node) {
            if ($href = $node->getAttribute("href")) {
                $href = Helpers::build_url($dompdf->getProtocol(), $dompdf->getBaseHost(), $dompdf->getBasePath(), $href) ?? $href;
                $this->_canvas->add_link($href, $x, $y, $w, $h);
            }
        }
    }

    protected function get_child_size(Frame $frame, bool $do_debug_layout_line): array
    {
        $w = 0.0;
        $h = 0.0;

        foreach ($frame->get_children() as $child) {
            if ($child->get_node()->nodeValue === " " && $child->get_prev_sibling() && !$child->get_next_sibling()) {
                break;
            }

            $style = $child->get_style();
            list(, , $child_w, $child_h) = $child->get_padding_box();

            $child_h2 = 0.0;

            if ($style->width === "auto") {
                list($child_w, $child_h2) = $this->get_child_size($child, $do_debug_layout_line);
            }

            if ($style->height === "auto") {
                list(, $child_h2) = $this->get_child_size($child, $do_debug_layout_line);
            }

            $w += $child_w;
            $h = max($h, $child_h, $child_h2);

            if ($do_debug_layout_line) {
                $this->_debug_layout($child->get_border_box(), "blue");

                if ($this->_dompdf->getOptions()->getDebugLayoutPaddingBox()) {
                    $this->_debug_layout($child->get_padding_box(), "blue", [0.5, 0.5]);
                }
            }
        }

        return [$w, $h];
    }
}
