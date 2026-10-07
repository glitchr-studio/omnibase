const Encore = require('@symfony/webpack-encore');

const Webpack = require('webpack');
const WebpackBar = require('webpackbar');
const MediaQueryPlugin = require('@glitchr/media-query-plugin');
const path = require('path');

// Manually configure the runtime environment if not already configured yet by the "encore" command.
// It's useful when you use tools that rely on webpack.config.js file.
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore.addPlugin(new WebpackBar())

    .setOutputPath('./src/Resources/public/')
    .setPublicPath('/bundles/base')
    .setManifestKeyPrefix('.')

    .cleanupOutputBeforeBuild()
    .enableBuildNotifications()
    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning(Encore.isProduction())

    .copyFiles({from: './node_modules/@fortawesome/fontawesome-free/metadata/', pattern: /icons.yml$/, to: 'metadata/[name].[ext]'})
    .copyFiles({from: './node_modules/@fortawesome/fontawesome-free/webfonts/', to: 'fonts/[name].[hash].[ext]'})

    .copyFiles({from: './node_modules/@fortawesome/fontawesome-free/webfonts/', to: 'fonts/[name].[hash].[ext]'})
    .copyFiles({from: './node_modules/country-flag-icons/3x2/', to: 'images/flags/[path][name].[ext]', pattern: /\.svg$/})

    .copyFiles({from: './assets/styles/images/flags/', to: 'images/flags/[path][name].[ext]', pattern: /\.svg$/})
    .copyFiles({from: './assets/styles/fonts', to: 'fonts/[path][name].[ext]'})
    // Plain stylesheets linked by the bundle's own partials (credits.css: @Base/partials/_credits.html.twig).
    .copyFiles({from: './assets/styles/credits', to: 'css/[name].[ext]'})
    // Plain scripts a page links as they are (media.js: one thing sounds at a time, docs/40-commons/front-end.md).
    .copyFiles({from: './assets/media', to: 'js/[name].[ext]'})
    // highlight.js's theme for the editor's code blocks, served by the bundle (BSD-3-Clause, its licence
    // beside it) and linked by form-type-editor.js where an editor or code is shown - see the loader below.
    .copyFiles({from: './node_modules/highlight.js/styles/', pattern: /(^|\/)default\.css$/, includeSubdirectories: false, to: 'css/highlight.js/[name].[ext]'})
    .copyFiles({from: './node_modules/highlight.js/', pattern: /(^|\/)LICENSE$/, includeSubdirectories: false, to: 'css/highlight.js/LICENSE.txt'})
    .copyFiles({from: './assets/styles/images/bundles/', to: 'images/bundles/[path][name].[ext]'})
    .copyFiles({from: './assets/styles/images/', to: 'images/[path][name].[ext]', pattern: /\.(svg|webp|jpg|png|gif)$/})

    // The plain files copied above are published as they are, readable: not minified.
    .configureTerserPlugin((options) => {
        options.exclude = /^js\/media\.js$/;
    })
    .configureCssMinimizerPlugin((options) => {
        options.exclude = /^css\/credits\.css$/;
    })

    .disableSingleRuntimeChunk()

    // enables and configure @babel/preset-env polyfills
    .configureBabelPresetEnv((config) => {
        config.useBuiltIns = 'usage';
        config.corejs = '3.23';
    })

    // 'window.jQuery': 'jquery' is intentionally omitted: ProvidePlugin rewrites the
    // LHS of `window.jQuery = ...` to a local var, so the global would never be set.
    .addPlugin(new Webpack.ProvidePlugin({
        $: 'jquery',
        jQuery: 'jquery'
    }))

    .addEntry('base-async', './assets/base-async.js')
    .addEntry('base-defer', './assets/base-defer.js')
    .addEntry('admin-charts', './assets/admin-charts.js')
    .addEntry('notifications-defer', './assets/notifications.js')

    .addEntry('form-defer', './assets/form-defer.js')
    .addEntry('form-defer.editor', './assets/form-defer.editor.js')
    .addEntry('form-defer.array', './assets/form-defer.array.js')
    .addEntry('form-defer.datetime', './assets/form-defer.datetime.js')
    .addEntry('form-defer.select2', './assets/form-defer.select2.js')
    .addEntry('form-defer.color', './assets/form-defer.color.js')
    .addEntry('form-defer.code', './assets/form-defer.code.js')
    .addEntry('form-defer.wysiwyg', './assets/form-defer.wysiwyg.js')
    .addEntry('form-defer.cropper', './assets/form-defer.cropper.js')
    .addEntry('form-defer.emoji', './assets/form-defer.emoji.js')
    .addEntry('form-defer.dropzone', './assets/form-defer.dropzone.js')

    // editorjs-code-highlight's bundle @imports highlight.js's theme from cdnjs: dropped (assets/loaders/no-remote-import.js).
    .addRule({
        test: /[\\/]node_modules[\\/]editorjs-code-highlight[\\/].*\.js$/,
        enforce: 'pre',
        use: [{loader: path.resolve(__dirname, 'assets/loaders/no-remote-import.js')}]
    })

    .enablePostCssLoader()
    .enableSassLoader((options) => {
        options.sassOptions = {
            quietDeps: true
        };
    })
    
    .addLoader({
        test: /\.scss$/,
        use: [
            MediaQueryPlugin.loader,
            'postcss-loader',
            'sass-loader'
        ]
    })

    .addPlugin(new MediaQueryPlugin({
        include: ["base-async", "form-defer"],
        queries: {

          // Standard
          'all and (min-width: 1281px)': 'desktop',
          'all and (min-width: 1025px) and (max-width: 1280px)': 'laptop',
          'all and (min-width: 471px) and (max-width: 1024px)': 'tablet',
          'all and (min-width: 471px) and (max-width: 1024px) and (orientation: landscape)': 'tablet-landscape',
          'all and (max-width: 470px)': 'mobile',
          'all and (max-width: 470px) and (orientation: landscape)': 'mobile-landscape'
        }
    }))
;

module.exports = Encore.getWebpackConfig();
module.exports.watchOptions = { };
module.exports.snapshot = { managedPaths: [/^(.+?[\\/]node_modules)[\\/]((?!.*)).*[\\/]*/] };
module.exports.ignoreWarnings = [{
    module: /node_modules/
}];

// Fix jQuery 4.x ESM: alias to the CJS bundler-require-wrapper so that both
// `require('jquery')` and ProvidePlugin get the function, not the module namespace.
module.exports.resolve = module.exports.resolve || {};
module.exports.resolve.alias = module.exports.resolve.alias || {};
module.exports.resolve.alias['jquery'] = path.resolve(
    path.dirname(require.resolve('jquery')),
    'wrappers/jquery.bundler-require-wrapper.js'
);