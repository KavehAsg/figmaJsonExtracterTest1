// code.js

function rgbaToCss(value) {
    if (!value) return "transparent";
    const r = Math.round(value.r * 255);
    const g = Math.round(value.g * 255);
    const b = Math.round(value.b * 255);
    const a = value.a !== undefined ? value.a : 1;

    if (a === 1) {
        const toHex = function (c) {
            const hex = c.toString(16);
            return hex.length === 1 ? '0' + hex : hex;
        };
        return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
    } else {
        return `rgba(${r}, ${g}, ${b}, ${a.toFixed(2)})`;
    }
}

const runPlugin = async function () {
    console.clear();
    console.log("⏳ ترمه در حال استخراج دکمه‌ها، ابعاد و متغیرها...");

    try {
        const globalTokens = {};
        const varsMap = {};

        const getVarName = async function (varId) {
            if (varsMap[varId]) return varsMap[varId];
            try {
                const v = await figma.variables.getVariableByIdAsync(varId);
                if (v) {
                    varsMap[varId] = v.name;
                    return v.name;
                }
            } catch (e) { }
            return null;
        };

        // استخراج استایل‌ها و متغیرها
        const paintStyles = await figma.getLocalPaintStylesAsync();
        for (const style of paintStyles) {
            if (style.paints && style.paints.length > 0 && style.paints[0].type === 'SOLID') {
                const paint = style.paints[0];
                const opacityValue = paint.opacity !== undefined ? paint.opacity : 1;
                globalTokens[style.name] = rgbaToCss({ r: paint.color.r, g: paint.color.g, b: paint.color.b, a: opacityValue });
                varsMap[style.id] = style.name;
            }
        }

        const localVars = await figma.variables.getLocalVariablesAsync();
        for (const v of localVars) {
            varsMap[v.id] = v.name;
            const modeKeys = Object.keys(v.valuesByMode);
            if (modeKeys.length > 0) {
                let rawValue = v.valuesByMode[modeKeys[0]];
                if (rawValue && typeof rawValue === 'object' && rawValue.type === 'VARIABLE_ALIAS') {
                    const targetVar = await figma.variables.getVariableByIdAsync(rawValue.id);
                    if (targetVar) globalTokens[v.name] = `var(--${targetVar.name.replace(/[\s/]/g, '-').toLowerCase()})`;
                } else if (v.resolvedType === 'COLOR') {
                    globalTokens[v.name] = rgbaToCss(rawValue);
                } else if (v.resolvedType === 'FLOAT') {
                    globalTokens[v.name] = rawValue + 'px';
                } else {
                    globalTokens[v.name] = rawValue;
                }
            }
        }

        // ===== GLOBAL DEEP EXTRACTION HELPERS =====
        // These helpers are used by multiple SMART_WIDGET handlers.

        // Helper: Map Figma font style names to CSS numeric weights
        const mapFigmaWeightToNumber = function (styleName) {
            if (!styleName) return 400;
            const s = styleName.replace(/[\s-_]/g, '').toLowerCase();
            const weightMap = {
                'thin': 100,
                'hairline': 100,
                'extralight': 200,
                'ultralight': 200,
                'light': 300,
                'regular': 400,
                'normal': 400,
                'medium': 500,
                'semibold': 600,
                'demibold': 600,
                'bold': 700,
                'extrabold': 800,
                'ultrabold': 800,
                'black': 900,
                'heavy': 900,
            };
            // Strip italic suffix for matching
            const cleaned = s.replace('italic', '').trim();
            if (cleaned === '' || cleaned === 'italic') return 400;
            if (weightMap[cleaned] !== undefined) return weightMap[cleaned];
            // Fallback: try partial matching
            for (const key in weightMap) {
                if (cleaned.indexOf(key) !== -1) return weightMap[key];
            }
            return 400;
        };

        // Helper: Extract typography object from a TEXT node
        const extractTypography = async function (textNode) {
            if (!textNode || textNode.type !== 'TEXT') return null;
            const typo = {};

            // Font Family
            if (textNode.fontName && textNode.fontName !== figma.mixed) {
                typo.fontFamily = textNode.fontName.family;
                typo.fontWeight = mapFigmaWeightToNumber(textNode.fontName.style);
            } else {
                // Mixed fonts — take first segment
                typo.fontFamily = 'Inter';
                typo.fontWeight = 400;
            }

            // Font Size (raw value)
            if (textNode.fontSize !== undefined && textNode.fontSize !== figma.mixed) {
                typo.fontSize = textNode.fontSize;
            }
            // Font Size (bound variable / token)
            if (textNode.boundVariables && textNode.boundVariables['fontSize']) {
                const fsToken = await getVarName(textNode.boundVariables['fontSize'].id);
                if (fsToken) typo.fontSizeToken = fsToken;
            }

            // Line Height
            if (textNode.lineHeight && textNode.lineHeight !== figma.mixed) {
                const lh = textNode.lineHeight;
                if (lh.unit === 'PIXELS') {
                    typo.lineHeight = lh.value;
                    typo.lineHeightUnit = 'px';
                } else if (lh.unit === 'PERCENT') {
                    typo.lineHeight = lh.value;
                    typo.lineHeightUnit = '%';
                }
                // AUTO → omit (browser default)
            }
            // Line Height (bound variable / token)
            if (textNode.boundVariables && textNode.boundVariables['lineHeight']) {
                const lhToken = await getVarName(textNode.boundVariables['lineHeight'].id);
                if (lhToken) typo.lineHeightToken = lhToken;
            }

            // Letter Spacing
            if (textNode.letterSpacing && textNode.letterSpacing !== figma.mixed) {
                const ls = textNode.letterSpacing;
                if (ls.unit === 'PIXELS') {
                    typo.letterSpacing = ls.value;
                    typo.letterSpacingUnit = 'px';
                } else if (ls.unit === 'PERCENT') {
                    // Figma PERCENT letter-spacing: convert to em
                    // Figma uses % of font size, CSS em is also relative to font size
                    typo.letterSpacing = parseFloat((ls.value / 100).toFixed(3));
                    typo.letterSpacingUnit = 'em';
                }
            }
            // Letter Spacing (bound variable / token)
            if (textNode.boundVariables && textNode.boundVariables['letterSpacing']) {
                const lsToken = await getVarName(textNode.boundVariables['letterSpacing'].id);
                if (lsToken) typo.letterSpacingToken = lsToken;
            }

            return typo;
        };

        // Deep Text Extraction (recursive)
        // M3 components nest text deep: Frame > State Layer > Label Text
        // We recursively find the FIRST Text node at any depth.
        const findFirstText = async function (n) {
            if (n.type === 'TEXT') return n;
            if ('children' in n && n.children.length > 0) {
                for (let i = 0; i < n.children.length; i++) {
                    const found = await findFirstText(n.children[i]);
                    if (found) return found;
                }
            }
            return null;
        };

        // Find first N text nodes (recursive)
        // Returns an array of up to `maxCount` TEXT nodes found depth-first.
        const findTextNodes = async function (n, maxCount, results) {
            if (!results) results = [];
            if (results.length >= maxCount) return results;
            if (n.type === 'TEXT') {
                results.push(n);
                return results;
            }
            if ('children' in n && n.children.length > 0) {
                for (let i = 0; i < n.children.length; i++) {
                    await findTextNodes(n.children[i], maxCount, results);
                    if (results.length >= maxCount) break;
                }
            }
            return results;
        };

        // Deep Background Fill Extraction
        // Checks node and immediate children for fill tokens/styles.
        // Returns the resolved fill token name, or null.
        const extractDeepBackground = async function (node) {
            // 1. Check main node for boundVariables['fills']
            if (node.boundVariables && node.boundVariables['fills']
                && Array.isArray(node.boundVariables['fills'])
                && node.boundVariables['fills'].length > 0) {
                const fillToken = await getVarName(node.boundVariables['fills'][0].id);
                if (fillToken) return fillToken;
            }
            // 2. Fallback: check main node's fillStyleId
            if (node.fillStyleId && node.fillStyleId !== "") {
                const fillStyleName = await getVarName(node.fillStyleId);
                if (fillStyleName) return fillStyleName;
            }
            // 3. M3 Deep Fill: check immediate children (e.g. "State layer" frames)
            if ("children" in node && node.children.length > 0) {
                for (let i = 0; i < node.children.length; i++) {
                    const child = node.children[i];
                    // Skip TEXT nodes — we only want container/frame children
                    if (child.type === 'TEXT') continue;

                    // Try child's boundVariables['fills']
                    if (child.boundVariables && child.boundVariables['fills']
                        && Array.isArray(child.boundVariables['fills'])
                        && child.boundVariables['fills'].length > 0) {
                        const childFillToken = await getVarName(child.boundVariables['fills'][0].id);
                        if (childFillToken) return childFillToken;
                    }
                    // Try child's fillStyleId
                    if (child.fillStyleId && child.fillStyleId !== "") {
                        const childFillStyle = await getVarName(child.fillStyleId);
                        if (childFillStyle) return childFillStyle;
                    }
                }
            }
            return null;
        };

        // Deep Icon Detection (recursive)
        // M3 components may have leading/trailing icons as VECTOR nodes
        // or small icon-wrapper Frames. We deep-scan to find them.
        const findIconAndText = async function (n, results) {
            if (!results) results = { icons: [], textIndex: -1, flatIndex: 0 };
            if (n.type === 'TEXT') {
                results.textIndex = results.flatIndex;
                results.flatIndex++;
                return results;
            }
            // Detect icon: VECTOR, BOOLEAN_OPERATION, or named 'icon'
            const nName = n.name ? n.name.toLowerCase() : '';
            const isIconNode = (n.type === 'VECTOR' || n.type === 'BOOLEAN_OPERATION')
                || nName.includes('icon')
                || (n.type === 'FRAME' && ('width' in n) && n.width <= 64 && n.height <= 64
                    && ('children' in n) && n.children.length > 0
                    && n.children.every(function (gc) {
                        return gc.type === 'VECTOR' || gc.type === 'BOOLEAN_OPERATION';
                    }));

            if (isIconNode) {
                const iconInfo = { name: n.name || 'icon', index: results.flatIndex };
                // Extract icon color from boundVariables['fills'] or fillStyleId
                if (n.boundVariables && n.boundVariables['fills']
                    && Array.isArray(n.boundVariables['fills'])
                    && n.boundVariables['fills'].length > 0) {
                    const icToken = await getVarName(n.boundVariables['fills'][0].id);
                    if (icToken) iconInfo.colorToken = icToken;
                }
                if (!iconInfo.colorToken && n.fillStyleId && n.fillStyleId !== "") {
                    const icStyle = await getVarName(n.fillStyleId);
                    if (icStyle) iconInfo.colorToken = icStyle;
                }
                // Also check vector children for fill tokens
                if (!iconInfo.colorToken && 'children' in n && n.children.length > 0) {
                    for (let j = 0; j < n.children.length; j++) {
                        const vc = n.children[j];
                        if (vc.type === 'VECTOR') {
                            if (vc.boundVariables && vc.boundVariables['fills']
                                && Array.isArray(vc.boundVariables['fills'])
                                && vc.boundVariables['fills'].length > 0) {
                                const vcToken = await getVarName(vc.boundVariables['fills'][0].id);
                                if (vcToken) { iconInfo.colorToken = vcToken; break; }
                            }
                            if (!iconInfo.colorToken && vc.fillStyleId && vc.fillStyleId !== "") {
                                const vcStyle = await getVarName(vc.fillStyleId);
                                if (vcStyle) { iconInfo.colorToken = vcStyle; break; }
                            }
                        }
                    }
                }
                results.icons.push(iconInfo);
                results.flatIndex++;
                return results;
            }
            // Recurse into children
            if ('children' in n && n.children.length > 0) {
                for (let i = 0; i < n.children.length; i++) {
                    await findIconAndText(n.children[i], results);
                }
            }
            return results;
        };

        // Helper: Extract text color token from a TEXT node
        const extractTextColorToken = async function (textNode) {
            if (textNode.boundVariables && textNode.boundVariables['fills']
                && Array.isArray(textNode.boundVariables['fills'])
                && textNode.boundVariables['fills'].length > 0) {
                const tcToken = await getVarName(textNode.boundVariables['fills'][0].id);
                if (tcToken) return tcToken;
            }
            if (textNode.fillStyleId && textNode.fillStyleId !== "") {
                const textStyleName = await getVarName(textNode.fillStyleId);
                if (textStyleName) return textStyleName;
            }
            return null;
        };

        // موتور پیمایش
        let structureResult = null;
        const traverseNode = async function (node) {
            const nodeData = {
                id: node.id,
                name: node.name,
                type: node.type,
                layoutMode: node.layoutMode ? node.layoutMode : "NONE",
                tokens: {},
                rawValues: {}
            };

            if ("width" in node) nodeData.rawValues.width = node.width;
            if ("height" in node) nodeData.rawValues.height = node.height;

            if (node.boundVariables) {
                if (node.boundVariables['width']) {
                    const wToken = await getVarName(node.boundVariables['width'].id);
                    if (wToken) nodeData.tokens.width = wToken;
                }
                if (node.boundVariables['height']) {
                    const hToken = await getVarName(node.boundVariables['height'].id);
                    if (hToken) nodeData.tokens.height = hToken;
                }
            }

            if ("layoutMode" in node && node.layoutMode !== "NONE") {
                nodeData.rawValues.gap = node.itemSpacing;
                if (node.boundVariables && node.boundVariables['itemSpacing']) {
                    const tokenName = await getVarName(node.boundVariables['itemSpacing'].id);
                    if (tokenName) nodeData.tokens.gap = tokenName;
                }
            }

            if ("fillStyleId" in node && node.fillStyleId !== "") {
                const styleName = await getVarName(node.fillStyleId);
                if (styleName) nodeData.tokens.fill = styleName;
            }

            // --- Smart Widget Detection ---
            const lowerName = node.name ? node.name.toLowerCase() : '';
            const isButton = lowerName.includes('button');
            if (isButton) {
                nodeData.type = "SMART_WIDGET";
                nodeData.widgetType = "button";
                nodeData.settings = {};

                // --- Padding (unchanged) ---
                if ("paddingTop" in node) {
                    nodeData.rawValues.paddingTop = node.paddingTop;
                    nodeData.rawValues.paddingRight = node.paddingRight;
                    nodeData.rawValues.paddingBottom = node.paddingBottom;
                    nodeData.rawValues.paddingLeft = node.paddingLeft;
                }

                // --- Border Radius (unchanged) ---
                if ("topLeftRadius" in node) {
                    nodeData.rawValues.topLeftRadius = node.topLeftRadius;
                    nodeData.rawValues.topRightRadius = node.topRightRadius;
                    nodeData.rawValues.bottomRightRadius = node.bottomRightRadius;
                    nodeData.rawValues.bottomLeftRadius = node.bottomLeftRadius;
                }

                // --- Bound variable tokens for padding & radius (unchanged) ---
                if (node.boundVariables) {
                    const props = ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'topLeftRadius', 'topRightRadius', 'bottomRightRadius', 'bottomLeftRadius'];
                    for (let i = 0; i < props.length; i++) {
                        if (node.boundVariables[props[i]]) {
                            const tName = await getVarName(node.boundVariables[props[i]].id);
                            if (tName) nodeData.tokens[props[i]] = tName;
                        }
                    }
                }

                // --- Deep Text Extraction (using global helper) ---
                const textNode = await findFirstText(node);
                if (textNode) {
                    nodeData.settings.text = textNode.characters;

                    // Text color: using global extractTextColorToken helper
                    const textColorToken = await extractTextColorToken(textNode);
                    if (textColorToken) {
                        nodeData.tokens.textColor = textColorToken;
                    }

                    // Typography extraction for button text
                    const btnTypo = await extractTypography(textNode);
                    if (btnTypo) {
                        nodeData.typography = btnTypo;
                    }
                }

                // --- Deep Background Fill Extraction (using global helper) ---
                const fillToken = await extractDeepBackground(node);
                if (fillToken) {
                    nodeData.tokens.fill = fillToken;
                }

                // --- Deep Icon Detection inside Button (using global helper) ---
                const iconScan = await findIconAndText(node, null);
                if (iconScan.icons.length > 0) {
                    const firstIcon = iconScan.icons[0];
                    nodeData.settings.iconName = firstIcon.name;
                    // Position: if icon appears before text → left, after → right
                    nodeData.settings.iconAlign = (iconScan.textIndex === -1 || firstIcon.index < iconScan.textIndex) ? 'left' : 'right';
                    if (firstIcon.colorToken) {
                        nodeData.tokens.iconColor = firstIcon.colorToken;
                    }
                }

                return nodeData;
            }

            // --- Smart Form Grouping (Form Wrapper Logic) ---
            // Detects a parent 'form' FRAME/INSTANCE/COMPONENT and compiles
            // all child inputs + submit button into a single monolithic form node.
            const isForm = lowerName.includes('form') && (node.type === 'FRAME' || node.type === 'INSTANCE' || node.type === 'COMPONENT');
            if (isForm) {
                nodeData.type = "SMART_WIDGET";
                nodeData.widgetType = "elementor_form";
                nodeData.settings = { fields: [], submit_button: null };

                // Traverse immediate children to collect fields and button
                if ("children" in node && node.children.length > 0) {
                    for (let i = 0; i < node.children.length; i++) {
                        const child = node.children[i];
                        const childName = child.name ? child.name.toLowerCase() : '';

                        // --- Input / Textarea field ---
                        if (childName.includes('input') || childName.includes('textarea') || childName.includes('textfield') || childName.includes('search')) {
                            const fieldData = {
                                fieldType: childName.includes('textarea') ? 'textarea' : 'text',
                                placeholder: '',
                                tokens: {},
                                rawValues: {}
                            };

                            // Placeholder text (deep extraction for M3 nested labels)
                            const fieldTextNode = await findFirstText(child);
                            if (fieldTextNode) {
                                fieldData.placeholder = fieldTextNode.characters;
                            }

                            // Fill color via deep extraction
                            const fieldFill = await extractDeepBackground(child);
                            if (fieldFill) {
                                fieldData.tokens.fill = fieldFill;
                            }

                            // Border color (raw + token)
                            if (child.strokes && child.strokes.length > 0 && child.strokes[0].type === 'SOLID') {
                                const stroke = child.strokes[0];
                                const sOpacity = stroke.opacity !== undefined ? stroke.opacity : 1;
                                fieldData.rawValues.borderColor = rgbaToCss({ r: stroke.color.r, g: stroke.color.g, b: stroke.color.b, a: sOpacity });
                            }
                            if (child.boundVariables && child.boundVariables['strokes'] && Array.isArray(child.boundVariables['strokes']) && child.boundVariables['strokes'].length > 0) {
                                const strokeToken = await getVarName(child.boundVariables['strokes'][0].id);
                                if (strokeToken) fieldData.tokens.borderColor = strokeToken;
                            }

                            // Border radius (4 corners)
                            if ("topLeftRadius" in child) {
                                fieldData.rawValues.topLeftRadius = child.topLeftRadius;
                                fieldData.rawValues.topRightRadius = child.topRightRadius;
                                fieldData.rawValues.bottomRightRadius = child.bottomRightRadius;
                                fieldData.rawValues.bottomLeftRadius = child.bottomLeftRadius;
                            }
                            if (child.boundVariables) {
                                const rProps = ['topLeftRadius', 'topRightRadius', 'bottomRightRadius', 'bottomLeftRadius'];
                                for (let j = 0; j < rProps.length; j++) {
                                    if (child.boundVariables[rProps[j]]) {
                                        const tName = await getVarName(child.boundVariables[rProps[j]].id);
                                        if (tName) fieldData.tokens[rProps[j]] = tName;
                                    }
                                }
                            }

                            nodeData.settings.fields.push(fieldData);
                        }

                        // --- Submit Button ---
                        else if (childName.includes('button')) {
                            const btnData = {
                                text: '',
                                tokens: {},
                                rawValues: {}
                            };

                            // Button text (deep)
                            const btnTextNode = await findFirstText(child);
                            if (btnTextNode) {
                                btnData.text = btnTextNode.characters;
                                // Text color
                                const btnTextColor = await extractTextColorToken(btnTextNode);
                                if (btnTextColor) {
                                    btnData.tokens.textColor = btnTextColor;
                                }
                                // Typography for form submit button
                                const formBtnTypo = await extractTypography(btnTextNode);
                                if (formBtnTypo) {
                                    btnData.typography = formBtnTypo;
                                }
                            }

                            // Button background fill (deep)
                            const btnFill = await extractDeepBackground(child);
                            if (btnFill) {
                                btnData.tokens.fill = btnFill;
                            }

                            // Button border radius
                            if ("topLeftRadius" in child) {
                                btnData.rawValues.topLeftRadius = child.topLeftRadius;
                                btnData.rawValues.topRightRadius = child.topRightRadius;
                                btnData.rawValues.bottomRightRadius = child.bottomRightRadius;
                                btnData.rawValues.bottomLeftRadius = child.bottomLeftRadius;
                            }
                            if (child.boundVariables) {
                                const rProps = ['topLeftRadius', 'topRightRadius', 'bottomRightRadius', 'bottomLeftRadius'];
                                for (let j = 0; j < rProps.length; j++) {
                                    if (child.boundVariables[rProps[j]]) {
                                        const tName = await getVarName(child.boundVariables[rProps[j]].id);
                                        if (tName) btnData.tokens[rProps[j]] = tName;
                                    }
                                }
                            }

                            nodeData.settings.submit_button = btnData;
                        }
                    }
                }

                return nodeData;
            }

            // --- Image / Avatar Detection ---
            const hasImageFill = node.fills && Array.isArray(node.fills) && node.fills.some(function (p) { return p.type === 'IMAGE'; });
            const isImage = lowerName.includes('image') || lowerName.includes('avatar') || hasImageFill;
            if (isImage) {
                nodeData.type = "SMART_WIDGET";
                nodeData.widgetType = "image";
                nodeData.settings = {};

                // Border radius (4 corners)
                if ("topLeftRadius" in node) {
                    nodeData.rawValues.topLeftRadius = node.topLeftRadius;
                    nodeData.rawValues.topRightRadius = node.topRightRadius;
                    nodeData.rawValues.bottomRightRadius = node.bottomRightRadius;
                    nodeData.rawValues.bottomLeftRadius = node.bottomLeftRadius;
                }

                if (node.boundVariables) {
                    const rProps = ['topLeftRadius', 'topRightRadius', 'bottomRightRadius', 'bottomLeftRadius'];
                    for (let i = 0; i < rProps.length; i++) {
                        if (node.boundVariables[rProps[i]]) {
                            const tName = await getVarName(node.boundVariables[rProps[i]].id);
                            if (tName) nodeData.tokens[rProps[i]] = tName;
                        }
                    }
                }

                return nodeData;
            }

            // --- Divider / Line Detection ---
            const isDivider = lowerName.includes('divider') || lowerName.includes('line')
                || (node.type === 'LINE')
                || (node.type === 'RECTANGLE' && ('height' in node) && ('width' in node) && (node.height === 1 || node.width === 1));
            if (isDivider) {
                nodeData.type = "SMART_WIDGET";
                nodeData.widgetType = "divider";
                nodeData.settings = {};

                // Thickness
                if ("strokeWeight" in node) {
                    nodeData.rawValues.thickness = node.strokeWeight;
                } else if ("height" in node && "width" in node) {
                    nodeData.rawValues.thickness = Math.min(node.height, node.width);
                }

                // Raw color from fills or strokes
                if (node.fills && Array.isArray(node.fills) && node.fills.length > 0 && node.fills[0].type === 'SOLID') {
                    const fill = node.fills[0];
                    const fOp = fill.opacity !== undefined ? fill.opacity : 1;
                    nodeData.rawValues.color = rgbaToCss({ r: fill.color.r, g: fill.color.g, b: fill.color.b, a: fOp });
                } else if (node.strokes && node.strokes.length > 0 && node.strokes[0].type === 'SOLID') {
                    const stroke = node.strokes[0];
                    const sOp = stroke.opacity !== undefined ? stroke.opacity : 1;
                    nodeData.rawValues.color = rgbaToCss({ r: stroke.color.r, g: stroke.color.g, b: stroke.color.b, a: sOp });
                }

                // Bound variables for thickness
                if (node.boundVariables) {
                    if (node.boundVariables['strokeWeight']) {
                        const swToken = await getVarName(node.boundVariables['strokeWeight'].id);
                        if (swToken) nodeData.tokens.thickness = swToken;
                    }
                }

                return nodeData;
            }

            // --- Icon / SVG Detection ---
            let isIcon = lowerName.includes('icon');
            if (!isIcon && node.type === 'FRAME' && ('width' in node) && ('height' in node)
                && node.width <= 64 && node.height <= 64
                && ('children' in node) && node.children.length > 0) {
                const allVectors = node.children.every(function (c) {
                    return c.type === 'VECTOR' || c.type === 'BOOLEAN_OPERATION';
                });
                if (allVectors) isIcon = true;
            }
            if (isIcon) {
                nodeData.type = "SMART_WIDGET";
                nodeData.widgetType = "icon";
                nodeData.settings = {};

                nodeData.rawValues.iconSize = ('width' in node) ? node.width : 24;

                // Stroke color
                if (node.strokes && node.strokes.length > 0 && node.strokes[0].type === 'SOLID') {
                    const stroke = node.strokes[0];
                    const sOp = stroke.opacity !== undefined ? stroke.opacity : 1;
                    nodeData.rawValues.strokeColor = rgbaToCss({ r: stroke.color.r, g: stroke.color.g, b: stroke.color.b, a: sOp });
                }

                // Bound variables
                if (node.boundVariables) {
                    if (node.boundVariables['width']) {
                        const sizeToken = await getVarName(node.boundVariables['width'].id);
                        if (sizeToken) nodeData.tokens.iconSize = sizeToken;
                    }
                    if (node.boundVariables['strokes'] && Array.isArray(node.boundVariables['strokes']) && node.boundVariables['strokes'].length > 0) {
                        const strokeToken = await getVarName(node.boundVariables['strokes'][0].id);
                        if (strokeToken) nodeData.tokens.strokeColor = strokeToken;
                    }
                }

                // Check vector children for fill color tokens
                if ("children" in node && node.children.length > 0) {
                    for (let i = 0; i < node.children.length; i++) {
                        const child = node.children[i];
                        if (child.type === 'VECTOR' && child.fillStyleId && child.fillStyleId !== "") {
                            const vecFill = await getVarName(child.fillStyleId);
                            if (vecFill && !nodeData.tokens.fill) nodeData.tokens.fill = vecFill;
                            break;
                        }
                    }
                }

                return nodeData;
            }

            // --- Alert / Banner / Snackbar Detection ---
            const isAlert = lowerName.includes('alert') || lowerName.includes('banner') || lowerName.includes('snackbar');
            if (isAlert) {
                nodeData.type = "SMART_WIDGET";
                nodeData.widgetType = "alert";
                nodeData.settings = {};

                // Background fill via global deep extraction
                const alertFill = await extractDeepBackground(node);
                if (alertFill) {
                    nodeData.tokens.fill = alertFill;
                }

                // Alert text via global deep text extraction
                // Extract up to 2 text nodes for alert title + description
                const alertTextNodes = await findTextNodes(node, 2, null);
                if (alertTextNodes.length > 0) {
                    // First text = title, second text = description
                    nodeData.settings.title = alertTextNodes[0].characters;
                    // Extract text color token for title
                    const alertTitleColor = await extractTextColorToken(alertTextNodes[0]);
                    if (alertTitleColor) {
                        nodeData.tokens.titleTextColor = alertTitleColor;
                    }
                    // Typography for title
                    const titleTypo = await extractTypography(alertTextNodes[0]);
                    if (titleTypo) {
                        nodeData.titleTypography = titleTypo;
                    }

                    if (alertTextNodes.length > 1) {
                        nodeData.settings.description = alertTextNodes[1].characters;
                        const alertDescColor = await extractTextColorToken(alertTextNodes[1]);
                        if (alertDescColor) {
                            nodeData.tokens.descriptionTextColor = alertDescColor;
                        }
                        // Typography for description
                        const descTypo = await extractTypography(alertTextNodes[1]);
                        if (descTypo) {
                            nodeData.descriptionTypography = descTypo;
                        }
                    } else {
                        // Single text node — treat as description (Elementor alert default)
                        nodeData.settings.text = alertTextNodes[0].characters;
                        nodeData.tokens.textColor = alertTitleColor;
                    }
                }

                // Border radius
                if ("topLeftRadius" in node) {
                    nodeData.rawValues.topLeftRadius = node.topLeftRadius;
                    nodeData.rawValues.topRightRadius = node.topRightRadius;
                    nodeData.rawValues.bottomRightRadius = node.bottomRightRadius;
                    nodeData.rawValues.bottomLeftRadius = node.bottomLeftRadius;
                }
                if (node.boundVariables) {
                    const rProps = ['topLeftRadius', 'topRightRadius', 'bottomRightRadius', 'bottomLeftRadius'];
                    for (let i = 0; i < rProps.length; i++) {
                        if (node.boundVariables[rProps[i]]) {
                            const tName = await getVarName(node.boundVariables[rProps[i]].id);
                            if (tName) nodeData.tokens[rProps[i]] = tName;
                        }
                    }
                }

                // Check for dismiss icon via findIconAndText
                const alertIconScan = await findIconAndText(node, null);
                if (alertIconScan.icons.length > 0) {
                    // Check if any icon suggests dismiss/close
                    nodeData.settings.showDismiss = 'yes';
                }

                return nodeData;
            }


            // --- TEXT node: inject typography ---
            if (node.type === 'TEXT') {
                nodeData.rawValues.text = node.characters;
                // Text color
                const textFillToken = await extractTextColorToken(node);
                if (textFillToken) {
                    nodeData.tokens.textColor = textFillToken;
                }
                // Typography
                const textTypo = await extractTypography(node);
                if (textTypo) {
                    nodeData.typography = textTypo;
                }
            }


            if ("children" in node && node.children.length > 0) {
                const childrenPromises = node.children.map(function (child) { return traverseNode(child); });
                nodeData.children = await Promise.all(childrenPromises);
            }
            return nodeData;
        };

        if (figma.currentPage.selection.length > 0) {
            structureResult = await traverseNode(figma.currentPage.selection[0]);
        }

        const finalOutput = { designTokens: globalTokens, structure: structureResult };
        figma.ui.postMessage({ type: 'export-data', payload: finalOutput });

    } catch (error) {
        console.error("❌ ERROR:", error);
        figma.ui.postMessage({ type: 'error', message: String(error) });
    }
};

figma.showUI(__html__, { width: 500, height: 600 });
figma.ui.onmessage = msg => {
    if (msg.type === 'close') {
        figma.closePlugin();
    }
};
runPlugin();