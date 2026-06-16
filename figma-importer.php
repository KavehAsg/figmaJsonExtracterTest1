<?php
/**
 * Plugin Name: Figma to Elementor Importer
 * Description: نسخه پایدار با استخراج ابعاد، رنگ پس‌زمینه و رفع باگ UI
 * Version: 3.0.0
 * Author: Kaveh
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the Figma Importer admin menu page.
 */
function f2e_figma_menu()
{
    add_menu_page('Figma Importer', 'Figma Importer', 'manage_options', 'figma-importer-page', 'f2e_render_admin_page', 'dashicons-art', 100);
}
add_action('admin_menu', 'f2e_figma_menu');

/**
 * Inject Figma design tokens (colors & variables) into Elementor's Global Kit.
 * 
 * @param array  Key-value pairs of design tokens.
 * @return bool True on success, false on failure.
 */
function f2e_inject_tokens_to_global_kit($tokens)
{
    if (empty($tokens))
        return false;
    $kit_id = get_option('elementor_active_kit');
    if (!$kit_id)
        return false;

    $kit_settings = get_post_meta($kit_id, '_elementor_page_settings', true) ?: [];
    if (!isset($kit_settings['custom_colors']))
        $kit_settings['custom_colors'] = [];

    $css_variables = "/* Figma Design Tokens */\n:root {\n";
    foreach ($tokens as $name => $value) {
        $safe_name = str_replace([' ', '/'], '-', strtolower($name));
        $final_value = is_numeric($value) ? $value . 'px' : $value;
        $css_variables .= "  --{$safe_name}: {$final_value};\n";

        if (strpos($value, '#') === 0 || strpos($value, 'rgba') === 0) {
            $exists = false;
            foreach ($kit_settings['custom_colors'] as $cc) {
                if ($cc['title'] === $name) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $kit_settings['custom_colors'][] = ['_id' => substr(md5($name), 0, 7), 'title' => $name, 'color' => $value];
            }
        }
    }
    $css_variables .= "}\n";

    $existing_css = $kit_settings['custom_css'] ?? '';
    $existing_css = preg_replace('/\/\* Figma Design Tokens \*\/.*?\}\n/s', '', $existing_css);
    $kit_settings['custom_css'] = trim($existing_css) . "\n\n" . $css_variables;

    update_post_meta($kit_id, '_elementor_page_settings', $kit_settings);
    if (class_exists('\Elementor\Plugin'))
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    return true;
}

/**
 * Inject Figma Global Text Styles into Elementor Active Kit (custom_typography).
 * 
 * @param array  Array of typography styles from Figma.
 * @return bool True on success, false on failure.
 */
function f2e_inject_fonts_to_global_kit($global_typography)
{
    if (empty($global_typography) || !is_array($global_typography))
        return false;

    $kit_id = get_option('elementor_active_kit');
    if (!$kit_id)
        return false;

    $kit_settings = get_post_meta($kit_id, '_elementor_page_settings', true) ?: [];
    if (!isset($kit_settings['custom_typography']))
        $kit_settings['custom_typography'] = [];

    // Build a lookup of existing titles for deduplication
    $existing_titles = [];
    foreach ($kit_settings['custom_typography'] as $idx => $existing_font) {
        if (!empty($existing_font['title'])) {
            $existing_titles[$existing_font['title']] = $idx;
        }
    }

    foreach ($global_typography as $font) {
        if (empty($font['name'])) continue;

        $id = substr(md5($font['name']), 0, 7);
        $typo_settings = f2e_build_typography_array($font, 'typography');
        $new_global_font = array_merge(
            ['_id' => $id, 'title' => $font['name']],
            $typo_settings
        );

        if (isset($existing_titles[$font['name']])) {
            // Update existing entry
            $kit_settings['custom_typography'][$existing_titles[$font['name']]] = $new_global_font;
        } else {
            // Add new entry
            $kit_settings['custom_typography'][] = $new_global_font;
        }
    }

    update_post_meta($kit_id, '_elementor_page_settings', $kit_settings);
    if (class_exists('\Elementor\Plugin'))
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    return true;
}

/**
 * Build an Elementor dimensions array ensuring strict token or pixel rules.
 * 
 * @param array  Node data containing tokens and rawValues.
 * @param array  Mapping of Figma keys to Elementor keys.
 * @return array|null Dimension array for Elementor or null if empty.
 */
function f2e_build_dimensions_array($node, $figma_map)
{
    $has_token = false;
    $sides = [];
    foreach ($figma_map as $figma_key => $el_key) {
        if (!empty($node['tokens'][$figma_key])) {
            $has_token = true;
            $token_name = str_replace([' ', '/'], '-', strtolower($node['tokens'][$figma_key]));
            $sides[$el_key] = 'var(--' . $token_name . ')';
        } else {
            $raw = isset($node['rawValues'][$figma_key]) ? $node['rawValues'][$figma_key] : 0;
            $sides[$el_key] = $raw;
        }
    }
    $has_values = $has_token || !empty(array_filter($sides, function ($v) { return $v !== 0 && $v !== '0'; }));
    if (!$has_values) return null;

    if ($has_token) {
        foreach ($sides as $key => $val) {
            if (is_numeric($val)) {
                $sides[$key] = $val . 'px';
            }
        }
        return array_merge(['unit' => 'custom', 'isLinked' => false], $sides);
    } else {
        foreach ($sides as $key => $val) {
            $sides[$key] = intval($val);
        }
        return array_merge(['unit' => 'px', 'isLinked' => false], $sides);
    }
}

/**
 * Resolve a design token to its corresponding CSS variable string.
 * 
 * @param array  Node data.
 * @param string  The token key to resolve.
 * @return string|null CSS variable string or null if not found.
 */
function f2e_resolve_token($node, $token_key)
{
    if (!empty($node['tokens'][$token_key])) {
        $token_name = str_replace([' ', '/'], '-', strtolower($node['tokens'][$token_key]));
        return 'var(--' . $token_name . ')';
    }
    return null;
}

/**
 * Build an Elementor size array from a token or raw value.
 * 
 * @param array  Node data.
 * @param string  The figma key to extract size for.
 * @return array|null Size array with value and unit.
 */
function f2e_build_size_array($node, $figma_key)
{
    if (!empty($node['tokens'][$figma_key])) {
        $token_name = str_replace([' ', '/'], '-', strtolower($node['tokens'][$figma_key]));
        return ['size' => 'var(--' . $token_name . ')', 'unit' => 'custom'];
    } elseif (isset($node['rawValues'][$figma_key])) {
        return ['size' => intval($node['rawValues'][$figma_key]), 'unit' => 'px'];
    }
    return null;
}

// Helper: Build Elementor typography array from extracted typography data
// $typo_data is the typography object from Figma (fontFamily, fontWeight, fontSize, etc.)
// $prefix is the Elementor control prefix (e.g., 'typography', 'title_typography', 'description_typography')
function f2e_build_typography_array($typo_data, $prefix = 'typography')
{
    if (empty($typo_data)) return [];
    $result = [];

    // CRITICAL: Elementor requires this flag to enable custom typography override
    $result[$prefix . '_typography'] = 'custom';

    // Font Family
    if (!empty($typo_data['fontFamily'])) {
        $result[$prefix . '_font_family'] = $typo_data['fontFamily'];
    }

    // Font Weight
    if (!empty($typo_data['fontWeight'])) {
        $result[$prefix . '_font_weight'] = strval($typo_data['fontWeight']);
    }

    // Font Size (token or raw)
    if (!empty($typo_data['fontSizeToken'])) {
        $token_name = str_replace([' ', '/'], '-', strtolower($typo_data['fontSizeToken']));
        $result[$prefix . '_font_size'] = ['size' => 'var(--' . $token_name . ')', 'unit' => 'custom'];
    } elseif (isset($typo_data['fontSize'])) {
        $result[$prefix . '_font_size'] = ['size' => intval($typo_data['fontSize']), 'unit' => 'px'];
    }

    // Line Height (token or raw)
    if (!empty($typo_data['lineHeightToken'])) {
        $token_name = str_replace([' ', '/'], '-', strtolower($typo_data['lineHeightToken']));
        $result[$prefix . '_line_height'] = ['size' => 'var(--' . $token_name . ')', 'unit' => 'custom'];
    } elseif (isset($typo_data['lineHeight'])) {
        $unit = 'px';
        if (!empty($typo_data['lineHeightUnit'])) {
            $u = $typo_data['lineHeightUnit'];
            if ($u === '%') $unit = 'em';
            else $unit = $u;
        }
        $lh_value = $typo_data['lineHeight'];
        // Convert Figma percentage to em for Elementor
        if (!empty($typo_data['lineHeightUnit']) && $typo_data['lineHeightUnit'] === '%') {
            $lh_value = round($lh_value / 100, 2);
        }
        $result[$prefix . '_line_height'] = ['size' => $lh_value, 'unit' => $unit];
    }

    // Letter Spacing (token or raw)
    if (!empty($typo_data['letterSpacingToken'])) {
        $token_name = str_replace([' ', '/'], '-', strtolower($typo_data['letterSpacingToken']));
        $result[$prefix . '_letter_spacing'] = ['size' => 'var(--' . $token_name . ')', 'unit' => 'custom'];
    } elseif (isset($typo_data['letterSpacing'])) {
        $ls_unit = !empty($typo_data['letterSpacingUnit']) ? $typo_data['letterSpacingUnit'] : 'px';
        $result[$prefix . '_letter_spacing'] = ['size' => $typo_data['letterSpacing'], 'unit' => $ls_unit];
    }

    // Font Style (italic)
    if (!empty($typo_data['fontStyle'])) {
        $result[$prefix . '_font_style'] = $typo_data['fontStyle'];
    }

    // Text Transform (uppercase, lowercase, capitalize)
    if (!empty($typo_data['textTransform'])) {
        $result[$prefix . '_text_transform'] = $typo_data['textTransform'];
    }

    // Text Decoration (underline, line-through)
    if (!empty($typo_data['textDecoration'])) {
        $result[$prefix . '_text_decoration'] = $typo_data['textDecoration'];
    }

    return $result;
}

// ۳. موتور ترجمه پیشرفته با ابعاد و استایل
function convert_figma_node($node)
{
    if (!$node)
        return null;
    $el_id = substr(md5($node['id'] ?? uniqid()), 0, 7);

    // الف: Smart Widgets (تب‌ها و فرم)
    if (isset($node['type']) && $node['type'] === 'SMART_WIDGET') {
        if ($node['widgetType'] === 'tabs') {
            $elementor_tabs_settings = [];
            $elementor_tabs_containers = [];
            foreach ($node['settings']['tabs'] as $tab) {
                $tab_id = substr(md5(uniqid()), 0, 7);
                $elementor_tabs_settings[] = ['_id' => $tab_id, 'tab_title' => $tab['tab_title']];
                $elementor_tabs_containers[] = [
                    'id' => substr(md5(uniqid()), 0, 7),
                    'elType' => 'container',
                    'isInner' => true,
                    'settings' => [],
                    'elements' => [['id' => substr(md5(uniqid()), 0, 7), 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => $tab['tab_content']], 'elements' => []]]
                ];
            }
            return ['id' => $el_id, 'elType' => 'widget', 'widgetType' => 'nested-tabs', 'settings' => ['tabs' => $elementor_tabs_settings], 'elements' => $elementor_tabs_containers];
        }

        if ($node['widgetType'] === 'button') {
            $btn_settings = [
                'text' => $node['settings']['text'] ?? '',
            ];

            // Background Color
            if (!empty($node['tokens']['fill'])) {
                $bg_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['fill']));
                $btn_settings['background_color'] = 'var(--' . $bg_token . ')';
            }

            // Text Color
            if (!empty($node['tokens']['textColor'])) {
                $tc_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['textColor']));
                $btn_settings['button_text_color'] = 'var(--' . $tc_token . ')';
            }

            // Border Radius
            $radius_map = [
                'topLeftRadius' => 'top',
                'topRightRadius' => 'right',
                'bottomRightRadius' => 'bottom',
                'bottomLeftRadius' => 'left',
            ];
            $radius_has_token = false;
            $radius_sides = [];
            foreach ($radius_map as $figma_key => $el_key) {
                if (!empty($node['tokens'][$figma_key])) {
                    $radius_has_token = true;
                    $token_name = str_replace([' ', '/'], '-', strtolower($node['tokens'][$figma_key]));
                    $radius_sides[$el_key] = 'var(--' . $token_name . ')';
                } else {
                    $raw = isset($node['rawValues'][$figma_key]) ? $node['rawValues'][$figma_key] : 0;
                    $radius_sides[$el_key] = $raw;
                }
            }
            $has_radius = $radius_has_token || !empty(array_filter($radius_sides, function($v) { return $v !== 0 && $v !== '0'; }));
            if ($has_radius) {
                if ($radius_has_token) {
                    // At least one token: unit must be 'custom', all values as CSS var or raw string
                    foreach ($radius_sides as $key => $val) {
                        if (is_numeric($val)) {
                            $radius_sides[$key] = $val . 'px';
                        }
                    }
                    $btn_settings['border_radius'] = array_merge(
                        ['unit' => 'custom', 'isLinked' => false],
                        $radius_sides
                    );
                } else {
                    // No tokens: unit is 'px', values must be pure numbers
                    foreach ($radius_sides as $key => $val) {
                        $radius_sides[$key] = intval($val);
                    }
                    $btn_settings['border_radius'] = array_merge(
                        ['unit' => 'px', 'isLinked' => false],
                        $radius_sides
                    );
                }
            }

            // Padding
            $padding_map = [
                'paddingTop' => 'top',
                'paddingRight' => 'right',
                'paddingBottom' => 'bottom',
                'paddingLeft' => 'left',
            ];
            $padding_has_token = false;
            $padding_sides = [];
            foreach ($padding_map as $figma_key => $el_key) {
                if (!empty($node['tokens'][$figma_key])) {
                    $padding_has_token = true;
                    $token_name = str_replace([' ', '/'], '-', strtolower($node['tokens'][$figma_key]));
                    $padding_sides[$el_key] = 'var(--' . $token_name . ')';
                } else {
                    $raw = isset($node['rawValues'][$figma_key]) ? $node['rawValues'][$figma_key] : 0;
                    $padding_sides[$el_key] = $raw;
                }
            }
            $has_padding = $padding_has_token || !empty(array_filter($padding_sides, function($v) { return $v !== 0 && $v !== '0'; }));
            if ($has_padding) {
                if ($padding_has_token) {
                    // At least one token: unit must be 'custom', numeric values get 'px' suffix
                    foreach ($padding_sides as $key => $val) {
                        if (is_numeric($val)) {
                            $padding_sides[$key] = $val . 'px';
                        }
                    }
                    $btn_settings['text_padding'] = array_merge(
                        ['unit' => 'custom', 'isLinked' => false],
                        $padding_sides
                    );
                } else {
                    // No tokens: unit is 'px', values must be pure numbers
                    foreach ($padding_sides as $key => $val) {
                        $padding_sides[$key] = intval($val);
                    }
                    $btn_settings['text_padding'] = array_merge(
                        ['unit' => 'px', 'isLinked' => false],
                        $padding_sides
                    );
                }
            }

            // Icon (Smart Name Mapping: Figma/M3 icon name → Font Awesome)
            if (!empty($node['settings']['iconName'])) {
                $icon_name_raw = strtolower(trim($node['settings']['iconName']));
                // Normalize: strip common prefixes/suffixes, replace separators
                $icon_name_raw = preg_replace('/^(ic_|icon_|ico_)/', '', $icon_name_raw);
                $icon_name_raw = str_replace(['_', ' '], '-', $icon_name_raw);

                // M3 / Material → Font Awesome mapping table
                $icon_map = [
                    // Navigation & Arrows
                    'arrow-back'        => 'fas fa-arrow-left',
                    'arrow-forward'     => 'fas fa-arrow-right',
                    'arrow-upward'      => 'fas fa-arrow-up',
                    'arrow-downward'    => 'fas fa-arrow-down',
                    'arrow-left'        => 'fas fa-arrow-left',
                    'arrow-right'       => 'fas fa-arrow-right',
                    'chevron-left'      => 'fas fa-chevron-left',
                    'chevron-right'     => 'fas fa-chevron-right',
                    'expand-more'       => 'fas fa-chevron-down',
                    'expand-less'       => 'fas fa-chevron-up',
                    'menu'              => 'fas fa-bars',
                    'close'             => 'fas fa-times',
                    'cancel'            => 'fas fa-times-circle',

                    // Actions
                    'search'            => 'fas fa-search',
                    'add'               => 'fas fa-plus',
                    'remove'            => 'fas fa-minus',
                    'delete'            => 'fas fa-trash',
                    'edit'              => 'fas fa-pen',
                    'create'            => 'fas fa-plus',
                    'save'              => 'fas fa-save',
                    'send'              => 'fas fa-paper-plane',
                    'share'             => 'fas fa-share-alt',
                    'download'          => 'fas fa-download',
                    'upload'            => 'fas fa-upload',
                    'refresh'           => 'fas fa-sync',
                    'copy'              => 'fas fa-copy',
                    'print'             => 'fas fa-print',
                    'filter'            => 'fas fa-filter',
                    'sort'              => 'fas fa-sort',
                    'undo'              => 'fas fa-undo',
                    'redo'              => 'fas fa-redo',
                    'settings'          => 'fas fa-cog',
                    'tune'              => 'fas fa-sliders-h',
                    'more-vert'         => 'fas fa-ellipsis-v',
                    'more-horiz'        => 'fas fa-ellipsis-h',

                    // Content & Media
                    'image'             => 'fas fa-image',
                    'photo'             => 'fas fa-image',
                    'camera'            => 'fas fa-camera',
                    'video'             => 'fas fa-video',
                    'play'              => 'fas fa-play',
                    'pause'             => 'fas fa-pause',
                    'stop'              => 'fas fa-stop',
                    'mic'               => 'fas fa-microphone',
                    'volume-up'         => 'fas fa-volume-up',
                    'volume-off'        => 'fas fa-volume-mute',
                    'attachment'        => 'fas fa-paperclip',
                    'attach-file'       => 'fas fa-paperclip',
                    'link'              => 'fas fa-link',

                    // Communication
                    'email'             => 'fas fa-envelope',
                    'mail'              => 'fas fa-envelope',
                    'chat'              => 'fas fa-comment',
                    'message'           => 'fas fa-comment-dots',
                    'call'              => 'fas fa-phone',
                    'phone'             => 'fas fa-phone',
                    'notifications'     => 'fas fa-bell',
                    'notification'      => 'fas fa-bell',

                    // People & Account
                    'person'            => 'fas fa-user',
                    'account-circle'    => 'fas fa-user-circle',
                    'group'             => 'fas fa-users',
                    'people'            => 'fas fa-users',

                    // Status & Feedback
                    'check'             => 'fas fa-check',
                    'check-circle'      => 'fas fa-check-circle',
                    'done'              => 'fas fa-check',
                    'error'             => 'fas fa-exclamation-circle',
                    'warning'           => 'fas fa-exclamation-triangle',
                    'info'              => 'fas fa-info-circle',
                    'help'              => 'fas fa-question-circle',

                    // Shopping & Commerce
                    'shopping-cart'     => 'fas fa-shopping-cart',
                    'cart'              => 'fas fa-shopping-cart',
                    'store'             => 'fas fa-store',
                    'payment'           => 'fas fa-credit-card',
                    'credit-card'       => 'fas fa-credit-card',

                    // Content types
                    'home'              => 'fas fa-home',
                    'favorite'          => 'fas fa-heart',
                    'star'              => 'fas fa-star',
                    'bookmark'          => 'fas fa-bookmark',
                    'flag'              => 'fas fa-flag',
                    'lock'              => 'fas fa-lock',
                    'visibility'        => 'fas fa-eye',
                    'visibility-off'    => 'fas fa-eye-slash',
                    'calendar'          => 'fas fa-calendar-alt',
                    'event'             => 'fas fa-calendar-alt',
                    'schedule'          => 'fas fa-clock',
                    'location'          => 'fas fa-map-marker-alt',
                    'place'             => 'fas fa-map-marker-alt',
                    'map'               => 'fas fa-map',
                    'globe'             => 'fas fa-globe',
                    'language'          => 'fas fa-globe',
                    'file'              => 'fas fa-file',
                    'folder'            => 'fas fa-folder',
                    'cloud'             => 'fas fa-cloud',
                    'cloud-upload'      => 'fas fa-cloud-upload-alt',
                    'cloud-download'    => 'fas fa-cloud-download-alt',
                    'login'             => 'fas fa-sign-in-alt',
                    'logout'            => 'fas fa-sign-out-alt',
                    'exit'              => 'fas fa-sign-out-alt',

                    // Social
                    'thumb-up'          => 'fas fa-thumbs-up',
                    'thumb-down'        => 'fas fa-thumbs-down',
                ];

                // Try exact match first, then partial/fuzzy match
                $fa_class = null;
                if (isset($icon_map[$icon_name_raw])) {
                    $fa_class = $icon_map[$icon_name_raw];
                } else {
                    // Fuzzy: check if the icon name contains any key
                    foreach ($icon_map as $key => $value) {
                        if (strpos($icon_name_raw, $key) !== false) {
                            $fa_class = $value;
                            break;
                        }
                    }
                }

                // Fallback: unmapped icons get a generic placeholder
                if (!$fa_class) {
                    $fa_class = 'fas fa-star';
                }

                // Parse library and value from the FA class
                $fa_parts = explode(' ', $fa_class, 2);
                $btn_settings['selected_icon'] = [
                    'library' => ($fa_parts[0] === 'fab') ? 'fa-brands' : (($fa_parts[0] === 'far') ? 'fa-regular' : 'fa-solid'),
                    'value' => $fa_class,
                ];

                // Icon alignment (left = before text, right = after text)
                $btn_settings['icon_align'] = $node['settings']['iconAlign'] ?? 'left';

                // Icon spacing
                $btn_settings['icon_indent'] = ['size' => 8, 'unit' => 'px'];

                // Icon color (from token)
                if (!empty($node['tokens']['iconColor'])) {
                    $ic_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['iconColor']));
                    $btn_settings['icon_color'] = 'var(--' . $ic_token . ')';
                }
            }

            // Typography for button text
            $btn_typo = f2e_build_typography_array($node['typography'] ?? null, 'typography');
            if (!empty($btn_typo)) {
                $btn_settings = array_merge($btn_settings, $btn_typo);
            }

            return [
                'id' => $el_id,
                'elType' => 'widget',
                'widgetType' => 'button',
                'settings' => $btn_settings,
                'elements' => []
            ];
        }

        // Smart Form Grouping (Monolithic Elementor Form)
        if ($node['widgetType'] === 'elementor_form') {
            $fields = $node['settings']['fields'] ?? [];
            $submit = $node['settings']['submit_button'] ?? null;

            // Build form_fields array from collected fields
            $elementor_fields = [];
            foreach ($fields as $field) {
                $elementor_fields[] = [
                    '_id' => substr(md5(uniqid()), 0, 7),
                    'field_type' => $field['fieldType'] ?? 'text',
                    'placeholder' => $field['placeholder'] ?? '',
                    'width' => '100',
                ];
            }

            // Fallback: if no fields were found, add one empty text field
            if (empty($elementor_fields)) {
                $elementor_fields[] = [
                    '_id' => substr(md5(uniqid()), 0, 7),
                    'field_type' => 'text',
                    'placeholder' => '',
                    'width' => '100',
                ];
            }

            $form_settings = [
                'form_name' => 'Figma Form',
                'show_labels' => '',
                'form_fields' => $elementor_fields,
            ];

            // Global Field Styles (from the FIRST field)
            if (!empty($fields[0])) {
                $first_field = $fields[0];

                // Field background color
                if (!empty($first_field['tokens']['fill'])) {
                    $bg_token = str_replace([' ', '/'], '-', strtolower($first_field['tokens']['fill']));
                    $form_settings['field_background_color'] = 'var(--' . $bg_token . ')';
                }

                // Field border color
                if (!empty($first_field['tokens']['borderColor'])) {
                    $bc_token = str_replace([' ', '/'], '-', strtolower($first_field['tokens']['borderColor']));
                    $form_settings['field_border_color'] = 'var(--' . $bc_token . ')';
                } elseif (!empty($first_field['rawValues']['borderColor'])) {
                    $form_settings['field_border_color'] = $first_field['rawValues']['borderColor'];
                }

                // Field border radius
                $radius = f2e_build_dimensions_array($first_field, [
                    'topLeftRadius' => 'top',
                    'topRightRadius' => 'right',
                    'bottomRightRadius' => 'bottom',
                    'bottomLeftRadius' => 'left',
                ]);
                if ($radius) {
                    $form_settings['field_border_radius'] = $radius;
                }
            }

            // Submit Button Styles
            if ($submit) {
                // Button text
                if (!empty($submit['text'])) {
                    $form_settings['button_text'] = $submit['text'];
                }

                // Button background color
                if (!empty($submit['tokens']['fill'])) {
                    $btn_bg_token = str_replace([' ', '/'], '-', strtolower($submit['tokens']['fill']));
                    $form_settings['button_background_color'] = 'var(--' . $btn_bg_token . ')';
                }

                // Button text color
                if (!empty($submit['tokens']['textColor'])) {
                    $btn_tc_token = str_replace([' ', '/'], '-', strtolower($submit['tokens']['textColor']));
                    $form_settings['button_text_color'] = 'var(--' . $btn_tc_token . ')';
                }

                // Button border radius
                $btn_radius = f2e_build_dimensions_array($submit, [
                    'topLeftRadius' => 'top',
                    'topRightRadius' => 'right',
                    'bottomRightRadius' => 'bottom',
                    'bottomLeftRadius' => 'left',
                ]);
                if ($btn_radius) {
                    $form_settings['button_border_radius'] = $btn_radius;
                }
            }

            return [
                'id' => $el_id,
                'elType' => 'widget',
                'widgetType' => 'form',
                'settings' => $form_settings,
                'elements' => [],
            ];
        }

        // Image / Avatar
        if ($node['widgetType'] === 'image') {
            $img_settings = [
                'image' => ['url' => '', 'id' => ''],
                'image_size' => 'full',
            ];

            // Width
            $width_arr = f2e_build_size_array($node, 'width');
            if ($width_arr) {
                $img_settings['image_custom_dimension'] = ['width' => $width_arr['size'], 'height' => ''];
                $img_settings['image_size'] = 'custom';
            }

            // Border radius
            $radius = f2e_build_dimensions_array($node, [
                'topLeftRadius' => 'top',
                'topRightRadius' => 'right',
                'bottomRightRadius' => 'bottom',
                'bottomLeftRadius' => 'left',
            ]);
            if ($radius) {
                $img_settings['image_border_radius'] = $radius;
            }

            return [
                'id' => $el_id,
                'elType' => 'widget',
                'widgetType' => 'image',
                'settings' => $img_settings,
                'elements' => [],
            ];
        }

        // Divider / Line
        if ($node['widgetType'] === 'divider') {
            $div_settings = [
                'style' => 'solid',
            ];

            // Color
            $color = f2e_resolve_token($node, 'fill');
            if ($color) {
                $div_settings['color'] = $color;
            } elseif (!empty($node['rawValues']['color'])) {
                $div_settings['color'] = $node['rawValues']['color'];
            }

            // Weight (thickness)
            if (!empty($node['tokens']['thickness'])) {
                $token_name = str_replace([' ', '/'], '-', strtolower($node['tokens']['thickness']));
                $div_settings['weight'] = ['size' => 'var(--' . $token_name . ')', 'unit' => 'custom'];
            } elseif (isset($node['rawValues']['thickness'])) {
                $div_settings['weight'] = ['size' => intval($node['rawValues']['thickness']), 'unit' => 'px'];
            }

            return [
                'id' => $el_id,
                'elType' => 'widget',
                'widgetType' => 'divider',
                'settings' => $div_settings,
                'elements' => [],
            ];
        }

        // Icon / SVG Placeholder
        if ($node['widgetType'] === 'icon') {
            $icon_settings = [
                'selected_icon' => [
                    'library' => 'fa-solid',
                    'value' => 'fas fa-star',
                ],
                'view' => 'default',
            ];

            // Icon color (from fill token)
            $fill_color = f2e_resolve_token($node, 'fill');
            if ($fill_color) {
                $icon_settings['primary_color'] = $fill_color;
            }

            // Stroke color
            $stroke_color = f2e_resolve_token($node, 'strokeColor');
            if ($stroke_color) {
                $icon_settings['secondary_color'] = $stroke_color;
            } elseif (!empty($node['rawValues']['strokeColor'])) {
                $icon_settings['secondary_color'] = $node['rawValues']['strokeColor'];
            }

            // Icon size
            if (!empty($node['tokens']['iconSize'])) {
                $token_name = str_replace([' ', '/'], '-', strtolower($node['tokens']['iconSize']));
                $icon_settings['icon_size'] = ['size' => 'var(--' . $token_name . ')', 'unit' => 'custom'];
            } elseif (isset($node['rawValues']['iconSize'])) {
                $icon_settings['icon_size'] = ['size' => intval($node['rawValues']['iconSize']), 'unit' => 'px'];
            }

            return [
                'id' => $el_id,
                'elType' => 'widget',
                'widgetType' => 'icon',
                'settings' => $icon_settings,
                'elements' => [],
            ];
        }

        // Alert / Banner / Snackbar
        if ($node['widgetType'] === 'alert') {
            $alert_settings = [
                'alert_type' => 'info',
                'alert_title' => $node['settings']['title'] ?? '',
                'alert_description' => $node['settings']['description'] ?? ($node['settings']['text'] ?? 'Alert message'),
            ];

            // Show dismiss button
            if (!empty($node['settings']['showDismiss']) && $node['settings']['showDismiss'] === 'yes') {
                $alert_settings['show_dismiss'] = 'show';
            } else {
                $alert_settings['show_dismiss'] = 'hide';
            }

            // Background color from fill token
            if (!empty($node['tokens']['fill'])) {
                $bg_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['fill']));
                $alert_settings['background'] = 'var(--' . $bg_token . ')';
            }

            // Title text color
            if (!empty($node['tokens']['titleTextColor'])) {
                $tc_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['titleTextColor']));
                $alert_settings['title_color'] = 'var(--' . $tc_token . ')';
            }
            // Description text color
            if (!empty($node['tokens']['descriptionTextColor'])) {
                $dc_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['descriptionTextColor']));
                $alert_settings['description_color'] = 'var(--' . $dc_token . ')';
            }
            // Legacy single text color fallback
            if (!empty($node['tokens']['textColor']) && empty($alert_settings['title_color'])) {
                $tc_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['textColor']));
                $alert_settings['text_color'] = 'var(--' . $tc_token . ')';
            }

            // Title typography
            $title_typo = f2e_build_typography_array($node['titleTypography'] ?? null, 'title_typography');
            if (!empty($title_typo)) {
                $alert_settings = array_merge($alert_settings, $title_typo);
            }

            // Description typography
            $desc_typo = f2e_build_typography_array($node['descriptionTypography'] ?? null, 'description_typography');
            if (!empty($desc_typo)) {
                $alert_settings = array_merge($alert_settings, $desc_typo);
            }

            // Border radius
            $radius = f2e_build_dimensions_array($node, [
                'topLeftRadius' => 'top',
                'topRightRadius' => 'right',
                'bottomRightRadius' => 'bottom',
                'bottomLeftRadius' => 'left',
            ]);
            if ($radius) {
                $alert_settings['border_radius'] = $radius;
            }

            return [
                'id' => $el_id,
                'elType' => 'widget',
                'widgetType' => 'alert',
                'settings' => $alert_settings,
                'elements' => [],
            ];
        }


    }

    // ب: کانتینرها (Frame/Group/Section)
    if (in_array($node['type'], ['FRAME', 'GROUP', 'SECTION', 'INSTANCE', 'COMPONENT'])) {
        $element = [
            'id' => $el_id,
            'elType' => 'container',
            'settings' => [],
            'elements' => [],
            'isInner' => false
        ];

        // 1. تنظیم Gap
        if (!empty($node['tokens']['gap'])) {
            $token_css_var = 'var(--' . str_replace([' ', '/'], '-', strtolower($node['tokens']['gap'])) . ')';
            $element['settings']['gap'] = ['size' => $token_css_var, 'unit' => 'custom'];
        } elseif (isset($node['rawValues']['gap'])) {
            $element['settings']['gap'] = ['size' => $node['rawValues']['gap'], 'unit' => 'px'];
        }

        // 2. تنظیم Width (عرض)
        if (isset($node['rawValues']['width'])) {
            $element['settings']['content_width'] = 'full'; // برای اینکه المنتور اجازه بده عرض دلخواه بدیم
            if (!empty($node['tokens']['width'])) {
                $w_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['width']));
                $element['settings']['width'] = ['size' => 'var(--' . $w_token . ')', 'unit' => 'custom'];
            } else {
                $element['settings']['width'] = ['size' => $node['rawValues']['width'], 'unit' => 'px'];
            }
        }

        // 3. تنظیم Height (ارتفاع)
        if (isset($node['rawValues']['height'])) {
            if (!empty($node['tokens']['height'])) {
                $h_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['height']));
                $element['settings']['min_height'] = ['size' => 'var(--' . $h_token . ')', 'unit' => 'custom'];
            } else {
                $element['settings']['min_height'] = ['size' => $node['rawValues']['height'], 'unit' => 'px'];
            }
        }

        // 4. جهت چیدمان (Flex Direction)
        if (isset($node['layoutMode']) && $node['layoutMode'] !== 'NONE') {
            $element['settings']['flex_direction'] = ($node['layoutMode'] === 'HORIZONTAL') ? 'row' : 'column';
        }

        // 5. رنگ پس‌زمینه (Background Color)
        if (!empty($node['tokens']['fill'])) {
            $bg_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['fill']));
            $element['settings']['background_background'] = 'classic';
            $element['settings']['background_color'] = 'var(--' . $bg_token . ')';
        }



        // پیمایش فرزندان
        if (!empty($node['children'])) {
            foreach ($node['children'] as $child) {
                $converted = convert_figma_node($child);
                if ($converted)
                    $element['elements'][] = $converted;
            }
        }
        return $element;
    }

    // ج: متن
    if ($node['type'] === 'TEXT') {
        $heading_settings = ['title' => $node['rawValues']['text'] ?? ($node['name'] ?? 'Text')];

        // Text color
        if (!empty($node['tokens']['textColor'])) {
            $tc_token = str_replace([' ', '/'], '-', strtolower($node['tokens']['textColor']));
            $heading_settings['title_color'] = 'var(--' . $tc_token . ')';
        }

        // Typography
        $heading_typo = f2e_build_typography_array($node['typography'] ?? null, 'typography');
        if (!empty($heading_typo)) {
            $heading_settings = array_merge($heading_settings, $heading_typo);
        }

        return ['id' => $el_id, 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => $heading_settings, 'elements' => []];
    }
    return null;
}

/**
 * Controller for creating the Elementor template from the Figma payload.
 * 
 * @param array  The complete JSON payload from Figma.
 * @return int|string|bool Post ID on success, 'only_tokens' if no structure, or false on failure.
 */
function create_elementor_template($payload)
{
    $tokens = $payload['designTokens'] ?? [];
    $structure = $payload['structure'] ?? null;

    $tokens_ok = !empty($tokens) ? f2e_inject_tokens_to_global_kit($tokens) : false;

    // Inject global typography styles into Elementor Site Settings
    $global_typo = $payload['globalTypography'] ?? [];
    if (!empty($global_typo)) {
        f2e_inject_fonts_to_global_kit($global_typo);
    }

    if (!$structure)
        return $tokens_ok ? 'only_tokens' : false;

    $el_data = convert_figma_node($structure);
    if (!$el_data)
        return false;

    $post_id = wp_insert_post([
        'post_title' => 'Figma Import - ' . date('H:i:s'),
        'post_status' => 'publish',
        'post_type' => 'elementor_library',
    ]);

    if ($post_id) {
        update_post_meta($post_id, '_elementor_data', wp_slash(json_encode([$el_data])));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', 'section');
        return $post_id;
    }
    return false;
}

/**
 * Render the WordPress Admin Page for the Figma Importer.
 * Handles file uploads, JSON parsing, and feedback rendering.
 */
function f2e_render_admin_page()
{
    $message = '';
    $json_content = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check if file is uploaded
        if (isset($_FILES['figma_json_file']) && $_FILES['figma_json_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['figma_json_file']['tmp_name'];
            $json_content = file_get_contents($file_tmp);
        } elseif (!empty($_POST['figma_json'])) {
            // Fallback to text area
            $json_content = stripslashes($_POST['figma_json']);
        }

        if (!empty($json_content)) {
            $data = json_decode($json_content, true);
            if ($data) {
                $res = create_elementor_template($data);
                if ($res === 'only_tokens') {
                    $message = '<div class="ki-toast ki-toast--success"><span class="ki-toast__icon">✅</span> Design tokens synced successfully. No template was created.</div>';
                } elseif ($res) {
                    $edit_link = admin_url('post.php?post=' . $res . '&action=elementor');
                    $message = '<div class="ki-toast ki-toast--success"><span class="ki-toast__icon">🎉</span> Template created! <a href="' . $edit_link . '" target="_blank" class="ki-toast__link">Open in Elementor →</a></div>';
                } else {
                    $message = '<div class="ki-toast ki-toast--error"><span class="ki-toast__icon">❌</span> Error processing the import.</div>';
                }
            } else {
                $message = '<div class="ki-toast ki-toast--error"><span class="ki-toast__icon">❌</span> Invalid JSON format.</div>';
            }
        } elseif (isset($_POST['figma_json'])) {
            $message = '<div class="ki-toast ki-toast--error"><span class="ki-toast__icon">⚠️</span> Please upload a JSON file or paste JSON content.</div>';
        }
    }
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        .ki-wrap {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 640px;
            margin: 40px auto;
            -webkit-font-smoothing: antialiased;
        }

        .ki-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 28px;
        }

        .ki-logo {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #18A0FB 0%, #A259FF 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 14px rgba(24, 160, 251, 0.25);
            flex-shrink: 0;
        }

        .ki-logo svg {
            width: 22px;
            height: 22px;
            fill: #fff;
        }

        .ki-header-text h1 {
            font-size: 20px;
            font-weight: 700;
            color: #1e1e1e;
            margin: 0 0 2px 0;
            padding: 0;
            letter-spacing: -0.02em;
        }

        .ki-header-text p {
            font-size: 13px;
            color: #8c8c8c;
            margin: 0;
        }

        .ki-toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
            animation: ki-slideDown 0.3s ease;
        }

        @keyframes ki-slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .ki-toast--success {
            background: #ECFDF5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }

        .ki-toast--error {
            background: #FEF2F2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        .ki-toast__icon {
            font-size: 16px;
            flex-shrink: 0;
        }

        .ki-toast__link {
            color: #18A0FB;
            text-decoration: none;
            font-weight: 600;
            margin-left: auto;
            white-space: nowrap;
        }

        .ki-toast__link:hover {
            text-decoration: underline;
        }

        .ki-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #eaeaea;
            border: none;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
            padding: 32px;
        }

        .ki-section {
            margin-bottom: 28px;
        }

        .ki-section:last-of-type {
            margin-bottom: 0;
        }

        .ki-section-title {
            font-size: 13px;
            font-weight: 600;
            color: #1e1e1e;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ki-section-title .ki-badge {
            font-size: 10px;
            font-weight: 600;
            background: #EEF2FF;
            color: #4F46E5;
            padding: 2px 8px;
            border-radius: 99px;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .ki-section-desc {
            font-size: 12px;
            color: #8c8c8c;
            margin: 0 0 14px 0;
            line-height: 1.5;
        }

        .ki-file-input {
            display: block;
            width: 100%;
            padding: 12px 16px;
            background: #f9fafb;
            border: 1.5px dashed #d1d5db;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            color: #6b7280;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .ki-file-input:hover {
            border-color: #18A0FB;
            background: #f0f9ff;
        }

        .ki-file-input:focus {
            outline: none;
            border-color: #18A0FB;
            box-shadow: 0 0 0 3px rgba(24, 160, 251, 0.12);
        }

        .ki-divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 24px 0;
            color: #d1d5db;
            font-size: 12px;
            font-weight: 500;
        }

        .ki-divider::before,
        .ki-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }

        .ki-textarea {
            display: block;
            width: 100%;
            height: 140px;
            padding: 14px 16px;
            background: #f9fafb;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            font-family: 'JetBrains Mono', 'Fira Code', 'SF Mono', Consolas, monospace;
            font-size: 12px;
            line-height: 1.6;
            color: #374151;
            resize: vertical;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .ki-textarea:hover {
            border-color: #d1d5db;
        }

        .ki-textarea:focus {
            outline: none;
            border-color: #18A0FB;
            box-shadow: 0 0 0 3px rgba(24, 160, 251, 0.12);
            background: #fff;
        }

        .ki-textarea::placeholder {
            color: #9ca3af;
        }

        .ki-submit {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 13px 24px;
            margin-top: 28px;
            background: linear-gradient(135deg, #18A0FB 0%, #0D8EE9 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 14px rgba(24, 160, 251, 0.25);
            letter-spacing: -0.01em;
        }

        .ki-submit:hover {
            background: linear-gradient(135deg, #0D8EE9 0%, #0B7AD4 100%);
            box-shadow: 0 6px 20px rgba(24, 160, 251, 0.35);
            transform: translateY(-1px);
        }

        .ki-submit:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(24, 160, 251, 0.2);
        }

        .ki-submit svg {
            width: 18px;
            height: 18px;
            fill: currentColor;
        }
    </style>

    <div class="ki-wrap">
        <div class="ki-header">
            <div class="ki-logo">
                <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            </div>
            <div class="ki-header-text">
                <h1>Figma Importer</h1>
                <p>Import your Figma design system into Elementor.</p>
            </div>
        </div>

        <?php echo $message; ?>

        <div class="ki-card">
            <form method="post" enctype="multipart/form-data">

                <div class="ki-section">
                    <h3 class="ki-section-title">Upload JSON File <span class="ki-badge">Recommended</span></h3>
                    <p class="ki-section-desc">Export from the Figma plugin and drop the file here.</p>
                    <input type="file" name="figma_json_file" accept=".json" class="ki-file-input" />
                </div>

                <div class="ki-divider">or</div>

                <div class="ki-section">
                    <h3 class="ki-section-title">Paste JSON Content</h3>
                    <p class="ki-section-desc">Alternatively, paste the raw JSON output below.</p>
                    <textarea name="figma_json" class="ki-textarea" placeholder='{"designTokens": {...}, "structure": {...}}'></textarea>
                </div>

                <button type="submit" class="ki-submit">
                    <svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                    Smart Import
                </button>

            </form>
        </div>
    </div>
    <?php
}