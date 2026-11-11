/**
 * Rendu ECharts pour les widgets JS Filament (HasJsRenderer) et le composant <x-echart>.
 *
 * La configuration reçue de PHP : { option, height, group, hover }
 *  - option : option ECharts (JSON). Les chaînes "@nom" (voir FORMATTERS) sont remplacées par des fonctions.
 *  - group  : les graphiques d'un même groupe partagent infobulle et zoom (echarts.connect).
 *  - hover : mise en exergue des réglages liés au survol (voir setupHover).
 */
import * as echarts from 'echarts/core'
import { BarChart, CustomChart, GaugeChart, LineChart, ScatterChart } from 'echarts/charts'
import {
    AxisPointerComponent,
    DataZoomComponent,
    DatasetComponent,
    GraphicComponent,
    GridComponent,
    LegendComponent,
    MarkAreaComponent,
    MarkLineComponent,
    MarkPointComponent,
    TitleComponent,
    ToolboxComponent,
    TooltipComponent,
    VisualMapComponent,
} from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import langFR from 'echarts/lib/i18n/langFR.js'

echarts.use([
    BarChart, CustomChart, GaugeChart, LineChart, ScatterChart,
    AxisPointerComponent, DataZoomComponent, DatasetComponent, GraphicComponent, GridComponent, LegendComponent,
    MarkAreaComponent, MarkLineComponent, MarkPointComponent, TitleComponent, ToolboxComponent, TooltipComponent,
    VisualMapComponent, CanvasRenderer,
])
echarts.registerLocale('FR', langFR)

const nf1 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 })
const nf2 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })
const pad = (n) => String(n).padStart(2, '0')

const unit = (u, f = nf1) => (v) => (v === null || v === undefined || Number.isNaN(v) ? '—' : `${f.format(v)} ${u}`)

const FORMATTERS = {
    '@temp': unit('°C'),
    '@kelvin': unit('K'),
    '@kg': unit('kg'),
    '@kg2': unit('kg', nf2),
    '@pct': unit('%', nf1),
    '@pctUpTo100': (v) => (v <= 100 ? `${v} %` : ''),
    '@kw': unit('kW'),
    '@kwh': unit('kWh'),
    '@min': unit('min', nf1),
    '@eur': unit('€', nf2),
    '@num': (v) => (v === null || v === undefined ? '—' : nf1.format(v)),
    '@hour': (v) => {
        const d = new Date(v)
        return `${pad(d.getHours())}:${pad(d.getMinutes())}`
    },
    '@day': (v) => {
        const d = new Date(v)
        return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}`
    },
    '@daylong': (v) => new Date(v).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }),
    '@month': (v) => new Date(v).toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' }),
    '@axisTemp': (v) => `${v} °C`,
    // Infobulle des nuages de points « signature » : [T ext, kg, date]
    '@signatureTooltip': (p) => `${p.value[2] ?? ''}<br/>T ext : ${nf1.format(p.value[0])} °C<br/>${nf1.format(p.value[1])} kg/j`,
    // Infobulle du nuage loi d'eau : [T ext, consigne, mesurée, écart ambiance]
    '@curveTooltip': (p) => `T ext ${nf1.format(p.value[0])} °C<br/>Départ consigne ${nf1.format(p.value[1])} °C<br/>Départ mesuré ${nf1.format(p.value[2])} °C<br/>Écart ambiance ${nf1.format(p.value[3])} K`,
    // Infobulle (axe) de la loi d'eau : les courbes seulement, pas le nuage des consignes mesurées (trop de points)
    '@curveAxisTooltip': (params) => {
        const list = (Array.isArray(params) ? params : [params]).filter((p) => p.seriesType !== 'scatter' && p.value?.[1] != null)
        if (!list.length) return ''
        return `T ext ${nf1.format(list[0].value[0])} °C<br/>`
            + list.map((p) => `${p.marker}${p.seriesName} : <strong>${nf1.format(p.value[1])} °C</strong>`).join('<br/>')
    },
    // Frise (type Gantt) : data = [ligne, début, fin, libellé, couleur]
    '@ganttRender': (params, api) => {
        const row = api.value(0)
        const start = api.coord([api.value(1), row])
        const end = api.coord([api.value(2), row])
        const h = api.size([0, 1])[1] * 0.7
        const rect = { x: start[0], y: start[1] - h / 2, width: Math.max(1, end[0] - start[0]), height: h }
        const clipped = echarts.graphic.clipRectByRect(rect, {
            x: params.coordSys.x, y: params.coordSys.y, width: params.coordSys.width, height: params.coordSys.height,
        })
        return clipped && { type: 'rect', transition: ['shape'], shape: clipped, style: { fill: api.visual('color') } }
    },
    '@ganttTooltip': (p) => {
        const s = new Date(p.value[1]), e = new Date(p.value[2])
        const mins = Math.round((e - s) / 60000)
        return `${p.value[3]}<br/>${pad(s.getHours())}:${pad(s.getMinutes())} → ${pad(e.getHours())}:${pad(e.getMinutes())} (${mins} min)`
    },
}

function hydrate(value) {
    if (typeof value === 'string' && FORMATTERS[value]) return FORMATTERS[value]
    if (Array.isArray(value)) return value.map(hydrate)
    if (value && typeof value === 'object') {
        const out = {}
        for (const [k, v] of Object.entries(value)) out[k] = hydrate(v)
        return out
    }
    return value
}

const isDark = () => document.documentElement.classList.contains('dark')

function baseOption(dark) {
    const text = dark ? '#d4d4d8' : '#3f3f46'
    const line = dark ? '#3f3f46' : '#e4e4e7'
    return {
        backgroundColor: 'transparent',
        textStyle: { fontFamily: 'inherit', color: text },
        color: ['#f59e0b', '#3b82f6', '#ef4444', '#10b981', '#8b5cf6', '#ec4899', '#14b8a6', '#64748b', '#eab308'],
        legend: { textStyle: { color: text } },
        tooltip: { confine: true },
        _axis: { axisLine: { lineStyle: { color: line } }, splitLine: { lineStyle: { color: line } }, axisLabel: { color: text } },
    }
}

function applyAxisTheme(option, dark) {
    const { _axis } = baseOption(dark)
    for (const key of ['xAxis', 'yAxis']) {
        if (!option[key]) continue
        const axes = Array.isArray(option[key]) ? option[key] : [option[key]]
        axes.forEach((a) => {
            a.axisLine = { ...(_axis.axisLine), ...(a.axisLine || {}) }
            a.splitLine = { ...(_axis.splitLine), ...(a.splitLine || {}) }
            a.axisLabel = { ...(_axis.axisLabel), ...(a.axisLabel || {}) }
        })
    }
    return option
}

function buildOption(configuration) {
    const dark = isDark()
    const base = baseOption(dark)
    delete base._axis
    const option = hydrate(structuredClone(configuration.option || {}))
    for (const k of Object.keys(option)) if (option[k] === null) delete option[k]
    // Lisibilité : courbes sans symbole (la légende reprend alors le trait plein / pointillé), légende aérée
    for (const s of option.series || []) {
        if (s.type === 'line' && s.showSymbol === false && s.symbol === undefined) s.symbol = 'none'
    }
    const legendBase = { ...base.legend, itemWidth: 28, itemHeight: 10, itemGap: 14 }
    const legend = Array.isArray(option.legend)
        ? option.legend.map((l) => ({ ...legendBase, ...l }))
        : { ...legendBase, ...(option.legend || {}) }
    return applyAxisTheme({ ...base, ...option, legend }, dark)
}

/**
 * Mise en exergue des réglages au survol (évènement « oko-setting-hover » sur window, detail.keys = clés de réglages) :
 *  - une série (ou un point) peut porter « oko: ['cle', …] » : survol de la ligne / de la zone ;
 *  - configuration.hover = { points: [[x, ['cle', …]], …] } : survol de l'axe X (point le plus proche).
 */
function setupHover(chart, configuration) {
    chart.off('mouseover')
    chart.off('updateAxisPointer')
    chart.off('globalout')
    const series = configuration.option?.series || []
    const points = configuration.hover?.points || null
    if (!points && !series.some((s) => s.oko)) return

    let last = ''
    const emit = (keys) => {
        const sig = (keys || []).join(',')
        if (sig === last) return
        last = sig
        window.dispatchEvent(new CustomEvent('oko-setting-hover', { detail: { keys: keys || [] } }))
    }

    chart.on('mouseover', (p) => {
        const keys = p.data?.oko || series[p.seriesIndex]?.oko
        if (keys) emit(keys)
    })
    if (points) {
        chart.on('updateAxisPointer', (e) => {
            const x = e.axesInfo?.find((a) => a.axisDim === 'x')?.value
            if (x === undefined) return
            let best = null
            for (const pt of points) if (best === null || Math.abs(pt[0] - x) < Math.abs(best[0] - x)) best = pt
            if (best) emit(best[1])
        })
    }
    chart.on('globalout', () => emit([]))
}

export default function mountEchart({ host, props }) {
    let configuration = props.configuration || {}
    host.style.width = '100%'
    host.style.height = configuration.height || '360px'

    let chart = null
    const init = () => {
        chart?.dispose()
        chart = echarts.init(host, isDark() ? 'dark' : null, { renderer: 'canvas', locale: 'FR' })
        if (configuration.group) {
            chart.group = configuration.group
            echarts.connect(configuration.group)
        }
        chart.setOption(buildOption(configuration), true)
        setupHover(chart, configuration)
    }
    init()

    const resize = new ResizeObserver(() => chart?.resize())
    resize.observe(host)

    // Bascule clair / sombre de Filament
    const theme = new MutationObserver(() => init())
    theme.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

    return {
        update({ configuration: next }) {
            configuration = next || {}
            host.style.height = configuration.height || '360px'
            chart.setOption(buildOption(configuration), true)
            setupHover(chart, configuration)
        },
        destroy() {
            resize.disconnect()
            theme.disconnect()
            chart?.dispose()
            host.replaceChildren()
        },
    }
}
