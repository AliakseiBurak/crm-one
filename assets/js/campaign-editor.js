import { Editor, Extension, Node } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import TextStyle from '@tiptap/extension-text-style';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';
import Table from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';

const BLOCK_STYLE_TYPES = ['paragraph', 'heading', 'blockquote', 'tableCell', 'tableHeader'];

const STYLE_TYPES = [
    'paragraph',
    'heading',
    'blockquote',
    'bulletList',
    'orderedList',
    'listItem',
    'table',
    'tableRow',
    'tableCell',
    'tableHeader',
    'textStyle',
    'image',
    'horizontalRule',
    'link',
    'div',
    'bold',
    'italic',
    'underline',
    'strike',
    'code',
];

const parseStyle = (value) => {
    const map = new Map();
    String(value ?? '').split(';').forEach((declaration) => {
        const separatorIndex = declaration.indexOf(':');
        if (separatorIndex < 1) {
            return;
        }
        const property = declaration.slice(0, separatorIndex).trim().toLowerCase();
        const declarationValue = declaration.slice(separatorIndex + 1).trim();
        if (property && declarationValue) {
            map.set(property, declarationValue);
        }
    });
    return map;
};

const serializeStyle = (map) =>
    Array.from(map.entries())
        .map(([property, value]) => `${property}: ${value}`)
        .join('; ');

const InlineStyle = Extension.create({
    name: 'inlineStyle',
    addGlobalAttributes() {
        return [
            {
                types: STYLE_TYPES,
                attributes: {
                    style: {
                        default: null,
                        parseHTML: (element) => element.getAttribute('style') || null,
                        renderHTML: (attributes) => (attributes.style ? { style: attributes.style } : {}),
                    },
                },
            },
            {
                types: ['image'],
                attributes: {
                    width: {
                        default: null,
                        parseHTML: (element) => element.getAttribute('width') || null,
                        renderHTML: (attributes) => (attributes.width ? { width: attributes.width } : {}),
                    },
                    height: {
                        default: null,
                        parseHTML: (element) => element.getAttribute('height') || null,
                        renderHTML: (attributes) => (attributes.height ? { height: attributes.height } : {}),
                    },
                },
            },
        ];
    },
});

const Div = Node.create({
    name: 'div',
    group: 'block',
    content: 'block+',
    parseHTML() {
        return [{ tag: 'div' }];
    },
    renderHTML({ HTMLAttributes }) {
        return ['div', HTMLAttributes, 0];
    },
});

const EmailTable = Table.extend({
    renderHTML({ HTMLAttributes }) {
        return ['table', HTMLAttributes, ['tbody', 0]];
    },
});

const EmailLink = Link.extend({
    addAttributes() {
        return {
            href: { default: null },
            target: { default: '_blank' },
            rel: { default: 'noopener noreferrer nofollow' },
        };
    },
});

const EmailTableCell = TableCell.extend({
    addAttributes() {
        return {
            colspan: { default: 1 },
            rowspan: { default: 1 },
        };
    },
});

const EmailTableHeader = TableHeader.extend({
    addAttributes() {
        return {
            colspan: { default: 1 },
            rowspan: { default: 1 },
        };
    },
});

const StyleCommands = Extension.create({
    name: 'styleCommands',
    addCommands() {
        return {
            setBlockStyle:
                (property, value) =>
                ({ state, dispatch }) => {
                    const { $from } = state.selection;
                    const tr = state.tr;
                    let updated = false;
                    for (let depth = $from.depth; depth > 0; depth -= 1) {
                        const node = $from.node(depth);
                        if (!BLOCK_STYLE_TYPES.includes(node.type.name)) {
                            continue;
                        }
                        const style = parseStyle(node.attrs.style);
                        if (value) {
                            style.set(property, value);
                        } else {
                            style.delete(property);
                        }
                        const next = serializeStyle(style);
                        tr.setNodeMarkup($from.before(depth), undefined, {
                            ...node.attrs,
                            style: next === '' ? null : next,
                        });
                        updated = true;
                        break;
                    }
                    if (dispatch) {
                        dispatch(tr);
                    }

                    return updated;
                },
            setMarkStyle:
                (property, value) =>
                ({ state, dispatch }) => {
                    const markType = state.schema.marks.textStyle;
                    if (!markType) {
                        return false;
                    }
                    const tr = state.tr;
                    const { from, to, empty } = state.selection;
                    const applyTo = (styleValue) => {
                        const style = parseStyle(styleValue);
                        if (value) {
                            style.set(property, value);
                        } else {
                            style.delete(property);
                        }
                        const next = serializeStyle(style);

                        return next === '' ? null : next;
                    };
                    if (empty) {
                        const marks = state.storedMarks ?? state.selection.$from.marks();
                        const mark = marks.find((candidate) => candidate.type === markType);
                        const next = applyTo(mark ? mark.attrs.style : null);
                        const rest = marks.filter((candidate) => candidate.type !== markType);
                        tr.setStoredMarks(next === null ? rest : [...rest, markType.create({ style: next })]);
                        if (dispatch) {
                            dispatch(tr);
                        }

                        return true;
                    }
                    state.doc.nodesBetween(from, to, (node, position) => {
                        if (!node.isText) {
                            return;
                        }
                        const mark = node.marks.find((candidate) => candidate.type === markType);
                        const next = applyTo(mark ? mark.attrs.style : null);
                        tr.removeMark(position, position + node.nodeSize, markType);
                        if (next !== null) {
                            tr.addMark(position, position + node.nodeSize, markType.create({ style: next }));
                        }
                    });
                    if (dispatch) {
                        dispatch(tr);
                    }

                    return true;
                },
        };
    },
});

const root = document.querySelector('[data-campaign-editor]');

if (root) {
    const form = root.closest('form');
    const textarea = root.querySelector('textarea[name="body"]');
    const toolbar = root.querySelector('[data-editor-toolbar]');
    const surface = root.querySelector('[data-editor-surface]');
    const toggleSource = root.querySelector('[data-editor-toggle-source]');
    const imageForm = root.querySelector('[data-editor-image-form]');
    const imageUrl = root.querySelector('[data-editor-image-url]');
    const imageAlt = root.querySelector('[data-editor-image-alt]');
    const imageWidth = root.querySelector('[data-editor-image-width]');
    const imageError = root.querySelector('[data-editor-image-error]');
    const imageInsert = root.querySelector('[data-editor-image-insert]');
    const imageCancel = root.querySelector('[data-editor-image-cancel]');
    const colorInput = root.querySelector('[data-editor-color]');

    if (form && textarea && toolbar && surface) {
        const editor = new Editor({
            element: surface,
            extensions: [
                StarterKit.configure({ heading: { levels: [1, 2, 3, 4, 5, 6] }, codeBlock: false }),
                TextStyle,
                InlineStyle,
                StyleCommands,
                Underline,
                EmailLink.configure({
                    openOnClick: false,
                    autolink: true,
                    linkOnPaste: true,
                    protocols: ['https', 'mailto', 'tel'],
                    defaultProtocol: 'https',
                }),
                Image.extend({
                    parseHTML() {
                        return [{ tag: 'img[src^="https://"]' }];
                    },
                }).configure({ allowBase64: false }),
                Placeholder.configure({ placeholder: 'Напишите текст письма…' }),
                Div,
                EmailTable.configure({ resizable: false }),
                TableRow,
                EmailTableCell,
                EmailTableHeader,
            ],
            content: textarea.value,
            editorProps: { attributes: { class: 'campaign-editor__content' } },
            onUpdate: () => {
                if (mode === 'visual') {
                    textarea.value = serializeBody();
                }
            },
        });

        let mode = 'visual';

        const serializeBody = () => {
            const html = editor.getHTML();
            const hasText = editor.getText().trim() !== '';
            const hasMedia = /<(img|table|hr)\b/i.test(html);

            return hasText || hasMedia ? html : '';
        };

        const blockStyle = (property) => {
            const { $from } = editor.state.selection;
            for (let depth = $from.depth; depth > 0; depth -= 1) {
                const node = $from.node(depth);
                if (BLOCK_STYLE_TYPES.includes(node.type.name)) {
                    return parseStyle(node.attrs.style).get(property) ?? null;
                }
            }

            return null;
        };

        const refreshToolbar = () => {
            toolbar.querySelectorAll('[data-editor-command]').forEach((button) => {
                const command = button.dataset.editorCommand;
                let active = false;
                if (command === 'heading') {
                    active = editor.isActive('heading', { level: Number(button.dataset.level) });
                } else if (command === 'align') {
                    active = blockStyle('text-align') === button.dataset.align;
                } else {
                    active = editor.isActive(command);
                }
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        };

        const setToolbarDisabled = (disabled) => {
            toolbar
                .querySelectorAll('button:not([data-editor-toggle-source]), input')
                .forEach((control) => {
                    control.disabled = disabled;
                });
        };

        const setMode = (nextMode) => {
            if (nextMode === mode) {
                return;
            }
            if (nextMode === 'source') {
                textarea.value = serializeBody();
                textarea.classList.add('campaign-editor__source--visible');
                surface.setAttribute('hidden', '');
            } else {
                editor.commands.setContent(textarea.value, false);
                textarea.classList.remove('campaign-editor__source--visible');
                surface.removeAttribute('hidden');
            }
            mode = nextMode;
            setToolbarDisabled(mode === 'source');
            toggleSource.classList.toggle('is-active', mode === 'source');
            toggleSource.setAttribute('aria-pressed', mode === 'source' ? 'true' : 'false');
        };

        const showImageError = (message) => {
            imageError.textContent = message;
            imageError.hidden = false;
        };

        const resetImageForm = () => {
            imageUrl.value = '';
            imageAlt.value = '';
            imageWidth.value = '';
            imageError.textContent = '';
            imageError.hidden = true;
        };

        const openImageForm = () => {
            imageForm.hidden = false;
            imageUrl.focus();
        };

        const closeImageForm = () => {
            imageForm.hidden = true;
            resetImageForm();
        };

        const insertImage = () => {
            const src = imageUrl.value.trim();
            if (!/^https:\/\/\S+$/i.test(src)) {
                showImageError('Укажите URL изображения по https://');

                return;
            }
            const widthValue = imageWidth.value.trim();
            editor
                .chain()
                .focus()
                .insertContent({
                    type: 'image',
                    attrs: { src, alt: imageAlt.value.trim() || null, width: /^\d+$/.test(widthValue) ? widthValue : null },
                })
                .run();
            closeImageForm();
        };

        const applyLink = () => {
            const previous = editor.getAttributes('link').href ?? '';
            const href = window.prompt('URL ссылки:', previous || 'https://');
            if (href === null) {
                return;
            }
            const trimmed = href.trim();
            if (trimmed === '') {
                editor.chain().focus().unsetLink().run();

                return;
            }
            const chain = editor.chain().focus();
            if (editor.isActive('link')) {
                chain.extendMarkRange('link');
            }
            chain.setLink({ href: trimmed }).run();
        };

        const runCommand = (button) => {
            const command = button.dataset.editorCommand;
            const chain = editor.chain().focus();
            if (command === 'bold' || command === 'italic' || command === 'underline' || command === 'strike') {
                chain[`toggle${command[0].toUpperCase()}${command.slice(1)}`]().run();
            } else if (command === 'heading') {
                chain.toggleHeading({ level: Number(button.dataset.level) }).run();
            } else if (command === 'bulletList') {
                chain.toggleBulletList().run();
            } else if (command === 'orderedList') {
                chain.toggleOrderedList().run();
            } else if (command === 'link') {
                applyLink();
            } else if (command === 'align') {
                const align = button.dataset.align;
                editor.chain().focus().setBlockStyle('text-align', blockStyle('text-align') === align ? null : align).run();
            }
        };

        toolbar.addEventListener('click', (event) => {
            const button = event.target.closest('[data-editor-command], [data-editor-insert-image], [data-editor-toggle-source]');
            if (!button || button.disabled) {
                return;
            }
            event.preventDefault();
            if (button.hasAttribute('data-editor-toggle-source')) {
                setMode(mode === 'visual' ? 'source' : 'visual');

                return;
            }
            if (button.hasAttribute('data-editor-insert-image')) {
                openImageForm();

                return;
            }
            runCommand(button);
        });

        colorInput?.addEventListener('input', () => {
            editor.chain().focus().setMarkStyle('color', colorInput.value).run();
        });

        imageInsert?.addEventListener('click', insertImage);
        imageCancel?.addEventListener('click', closeImageForm);
        imageForm?.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeImageForm();
            }
            if (event.key === 'Enter' && event.target.matches('input')) {
                event.preventDefault();
                insertImage();
            }
        });

        const syncToTextarea = () => {
            if (mode === 'visual') {
                textarea.value = serializeBody();
            }
        };

        form.addEventListener('submit', syncToTextarea);
        document.addEventListener('campaign-preview:before-open', syncToTextarea);
        editor.on('transaction', refreshToolbar);
        editor.on('selectionUpdate', refreshToolbar);
        refreshToolbar();
    }
}
