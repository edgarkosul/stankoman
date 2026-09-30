/*
 * Прогон артворка маскота через SVGO. Разовый, вручную; результат
 * (`robot-head.svg`) коммитится, шага сборки в CI не появляется.
 * Команда — в README.md рядом.
 *
 * Три отключённых плагина пресета — не вкусовщина, а контракт с
 * `robot-mascot.js`: он ищет узлы по id и пишет им transform.
 *   cleanupIds     — переименовал бы #antenna, #pupil-left, #lid-left в id="a".."e";
 *   collapseGroups — схлопнул бы 11 групп в 6, и держаться стало бы не за что;
 *   removeViewBox  — снял бы единственное, чем задан масштаб (width/height у
 *                    артворка нет, размер целиком на CSS).
 *
 * floatPrecision: 0 — потому что viewBox 1254 юнита рисуется в круге 64 px:
 * один юнит это 1/20 пикселя, и вторая цифра после запятой описывает
 * тысячные доли пикселя. Зрачки округление не задевает: это <circle>
 * с cx/cy, а floatPrecision правит только данные путей.
 *
 * prefixIds — id в SVG глобальны для всего документа, а этот файл живёт
 * на живой странице рядом с чужой разметкой. Префикс изолирует и сами id,
 * и ссылки url(#…) в clip-path.
 *
 * seamStroke — лечение швов, см. комментарий у самого плагина.
 */
export default {
    multipass: true,
    js2svg: { indent: 0, pretty: false },
    plugins: [
        {
            name: 'preset-default',
            params: {
                overrides: {
                    cleanupIds: false,
                    collapseGroups: false,
                    removeViewBox: false,
                    convertPathData: { floatPrecision: 0 },
                },
            },
        },
        { name: 'prefixIds', params: { prefix: 'km', delim: '-', prefixClassNames: false } },
        seamStroke(),
    ],
};

/*
 * Швы между соседними контурами.
 *
 * Артворк обведён по растру: соседние фигуры стыкуются встык, не
 * перекрываясь. На каждой такой границе обе фигуры сглаживают свой край
 * независимо, закрывают примерно по половине граничного пикселя — и
 * четверть фона проходит насквозь. На белой странице это незаметно,
 * на синей читается как грязь по всей голове. Замер растеризацией:
 * в исходнике 197 узких щелей, после convertPathData их 445.
 *
 * Лечится не точностью. Прогон показал: вернуть исходное качество можно
 * только на floatPrecision 2, а это 230 КБ вместо 70, то есть отказ от
 * оптимизации целиком. Поэтому щели закрываются, а не уменьшаются:
 * каждый контур обводится СВОИМ ЖЕ цветом заливки, и обводка расширяет
 * фигуру на половину толщины в каждую сторону. Два соседа перекрываются,
 * шва нет.
 *
 * Шесть юнитов на viewBox 1254 — это 0.34 px при отрисовке в 72 px, то
 * есть прибавка невидима, а щели до шести юнитов (это все узкие классы)
 * закрываются целиком.
 *
 * Не трогаем defs: обводка у фигуры внутри clipPath ни на что не влияет,
 * клип считается по заливке. Не трогаем circle — это зрачки, у них нет
 * соседей, и растить их незачем.
 */
function seamStroke() {
    return {
        name: 'seamStroke',
        fn: () => {
            let inDefs = 0;

            return {
                element: {
                    enter: (node) => {
                        if (node.name === 'defs' || node.name === 'clipPath') {
                            inDefs += 1;

                            return;
                        }

                        if (node.name === 'svg') {
                            node.attributes['stroke-width'] = '6';
                            node.attributes['stroke-linejoin'] = 'round';
                            node.attributes['stroke-linecap'] = 'round';

                            return;
                        }

                        if (inDefs || node.name !== 'path') return;

                        const fill = node.attributes.fill;
                        if (!fill || fill === 'none' || fill.startsWith('url(')) return;

                        node.attributes.stroke = fill;
                    },
                    exit: (node) => {
                        if (node.name === 'defs' || node.name === 'clipPath') inDefs -= 1;
                    },
                },
            };
        },
    };
}
