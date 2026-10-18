import { Component, type ErrorInfo, type ReactNode } from 'react'
import { AlertTriangle, RefreshCw, RotateCcw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Notice } from './notice'

interface Props { children: ReactNode; label?: string }
interface State { error: Error | null }

const EXPLORER_KEYS = ['hiyori-explorer-views', 'hiyori-saved-queries', 'hiyori-explorer-eq', 'hiyori-explorer-favs', 'hiyori-explorer-recents', 'hiyori-explorer-onboarded']

/**
 * Recovery boundary: a render/event error anywhere renders a contained
 * "Something went wrong" card instead of white-screening the page.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // keep the stack in the console for debugging, but keep the page alive
    console.error('[ErrorBoundary]', this.props.label ?? 'view', error, info.componentStack)
  }

  private resetExplorer = () => {
    try {
      EXPLORER_KEYS.forEach(k => localStorage.removeItem(k))
    } catch { /* ignore */ }
    this.setState({ error: null })
    window.location.hash = ''
    window.location.reload()
  }

  render(): ReactNode {
    if (!this.state.error) return this.props.children
    return (
      <Notice
        tone="error"
        variant="card"
        className="mx-auto max-w-md flex-col items-center p-6 text-center"
        icon={<AlertTriangle className="h-8 w-8" />}
      >
        <h2 className="text-lg font-semibold">{this.props.label ?? 'Something went wrong'}</h2>
        <p className="mt-1 text-sm">
          {String(this.state.error?.message ?? this.state.error ?? 'Unknown error')}
        </p>
        {this.props.label === 'Explorer' && (
          <p className="mt-1 text-xs opacity-80">If this keeps happening, reset the cached Explorer data.</p>
        )}
        <div className="mt-4 flex items-center justify-center gap-2">
          <Button size="sm" onClick={() => this.setState({ error: null })}><RefreshCw className="mr-1.5 h-3.5 w-3.5" />Try again</Button>
          {this.props.label === 'Explorer' && (
            <Button size="sm" variant="destructive" onClick={this.resetExplorer}><RotateCcw className="mr-1.5 h-3.5 w-3.5" />Reset Explorer data</Button>
          )}
        </div>
      </Notice>
    )
  }
}
