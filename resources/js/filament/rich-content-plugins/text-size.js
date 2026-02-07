import { Mark } from '@tiptap/core'

const allowedSizes = [
    'text-xs',
    'text-sm',
    'text-base',
    'text-lg',
    'text-xl',
    'text-2xl',
    'text-3xl',
    'text-4xl',
    'text-5xl',
    'text-6xl',
    'text-7xl',
    'text-8xl',
    'text-9xl',
]

const normalizeSize = (value) =>
    typeof value === 'string' && allowedSizes.includes(value) ? value : null

const getClassList = (element) => {
    if (!element) {
        return []
    }

    if (element.classList) {
        return Array.from(element.classList)
    }

    const classAttr = element.getAttribute?.('class') || ''
    return classAttr.split(/\s+/).filter(Boolean)
}

export default Mark.create({
    name: 'textSize',

    parseHTML() {
        return [
            {
                tag: 'span',
                getAttrs: (element) => {
                    const dataSize = normalizeSize(
                        element.getAttribute?.('data-size'),
                    )

                    if (dataSize) {
                        return true
                    }

                    const classList = getClassList(element)
                    return allowedSizes.some((size) =>
                        classList.includes(size),
                    )
                },
            },
        ]
    },

    addAttributes() {
        return {
            'data-size': {
                default: null,
                parseHTML: (element) => {
                    const dataSize = normalizeSize(
                        element.getAttribute?.('data-size'),
                    )

                    if (dataSize) {
                        return dataSize
                    }

                    const classList = getClassList(element)
                    return (
                        allowedSizes.find((size) =>
                            classList.includes(size),
                        ) || null
                    )
                },
                renderHTML: (attributes) => {
                    const size = normalizeSize(attributes['data-size'])

                    if (!size) {
                        return {}
                    }

                    return { 'data-size': size }
                },
            },
        }
    },

    renderHTML({ HTMLAttributes }) {
        const attrs = { ...HTMLAttributes }
        const size = normalizeSize(HTMLAttributes['data-size'])
        const existingClass =
            typeof HTMLAttributes.class === 'string'
                ? HTMLAttributes.class
                : ''

        if (size) {
            const classes = [existingClass, size]
                .filter(Boolean)
                .join(' ')
                .trim()

            if (classes) {
                attrs.class = classes
            }

            attrs['data-size'] = size
        } else {
            if (existingClass) {
                attrs.class = existingClass
            }

            delete attrs['data-size']
        }

        return ['span', attrs, 0]
    },

    addCommands() {
        return {
            setTextSize:
                ({ size }) =>
                ({ commands }) => {
                    return commands.setMark(this.name, { 'data-size': size })
                },
            unsetTextSize:
                () =>
                ({ commands }) => {
                    return commands.unsetMark(this.name)
                },
        }
    },
})
