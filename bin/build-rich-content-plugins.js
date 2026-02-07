import * as esbuild from 'esbuild'
import { mkdir } from 'node:fs/promises'

const entryPoints = [
    'resources/js/filament/rich-content-plugins/text-size.js',
]

const outdir = 'resources/js/dist/filament/rich-content-plugins'

await mkdir(outdir, { recursive: true })

await esbuild.build({
    entryPoints,
    outdir,
    bundle: true,
    define: {
        'process.env.NODE_ENV': '"production"',
    },
    format: 'esm',
    mainFields: ['module', 'main'],
    minify: true,
    platform: 'neutral',
    sourcemap: false,
    sourcesContent: false,
    target: ['es2020'],
})
