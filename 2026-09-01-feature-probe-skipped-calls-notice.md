# Feature Probe — Skipped-Calls Visibility in the Profiler Panel

**Date:** 2026-09-01
**Status:** closed — all axes settled or deliberately left open; ready for implementation

## Frame

| Field | Value |
|---|---|
| Feature (one sentence) | Surface calls skipped by `CostTracker::compute()`'s per-call guard as a count metric in the profiler panel (mirroring "Total Calls") AND as a detailed list (model where resolvable, exception class) mirroring the existing `unconfiguredModels` notice. |
| New or extension? | Extension of `CostSnapshot` / `CostTracker` / `LlmCostCollector` / `llm_cost.html.twig`. |
| Material | Path M — code exists: the sibling `unconfiguredModels` mechanism, the per-call catch block (`src/Service/CostTracker.php`, shipped v0.5.0), the totals-metrics block and per-call table in the twig template. |
| Artifact path | project root (no `docs/`) |
| Rejected sibling idea | Per-call latency/duration — technically reachable via `RawHttpResult::getObject()->getInfo('total_time')` (Symfony HttpClient), but rejected as **out of scope for a cost-tracking bundle**: it's a generic AI-operations metric that belongs in `symfony/ai-bundle`'s own profiler collector (which already collects the same four raw fields, `vendor/symfony/ai-bundle/src/Profiler/DataCollector.php:39-43`), not here. Recorded per the handoff-package "Rejected" outcome, not as an open axis. |

## Stage 1 — reverse narrative (Path M, answered from material)

1. **What has to happen for it to occur?** — In `compute()`'s per-call loop, the try block (result/metadata retrieval, `resolveModelName()`, `ModelRegistryInterface::get()`, or `CostCalculatorInterface::calculateCost()`) throws for at least one call. Today the catch block only logs (optionally) and `continue`s; the new feature additionally records the skip into the snapshot (count + entry) so `LlmCostCollector`/the template can render it.
2. **What could have prevented it?** — No call in the request threw (the common good-path — mirrors why `unconfiguredModels`' notice is conditionally rendered) · `TraceablePlatform` not registered (`kernel.debug=false` → `DebugCompilerPass` returns early → empty `$platforms`, verified this session) · the process dies before `kernel.terminate`/`lateCollect()` fires — the skip happened but the profiler snapshot never renders (carried into axis 11).
3. **What has to happen for it to STOP applying?** — `CostTracker::reset()` (tagged `kernel.reset`) clears the memoized snapshot; the next `compute()` starts a fresh accumulator. Inherently per-request/per-command, never sticky; if the underlying cause is fixed, the next request simply has zero skips and the notice disappears — no explicit "resolved" state needed.

## Stage 2 — thirteen-axis display

| # | Axis | Verdict | Evidence / reasoning |
|---|---|---|---|
| 2 | States/transitions | derivable | A call has exactly two outcomes today: processed (`$calls[]`/`$byModel`, counted in `totals.calls`) or silently skipped (caught, `continue`d). The feature adds visibility for the second outcome without changing it (still not retried, still excluded from `totals.calls`). |
| 7 | Reversibility | derivable | `compute()` is memoized per snapshot, reset only via explicit `CostTracker::reset()`. A skip is not undoable within a request — the underlying LLM call already happened (real cost incurred upstream), this bundle just can't account for it. The notice reports **untracked** cost, not zero cost. |
| 6 | Failure/abuse | settled | Owner decision (round 1): show exception CLASS **and message**. Accepted risk: an unfiltered exception message (e.g. a DSN with credentials) can reach the panel — mitigated only by the existing boundary that the profiler is dev-only, gated by `kernel.debug` (axis 1), never a NEW filter added by this feature. |
| 1 | Actors | derivable | Same audience as every other profiler datum: the developer/operator viewing the Symfony profiler in dev, gated by `kernel.debug` — never an end-user of the host app. |
| 3 | Permissions | n/a | The bundle has no permission model of its own; visibility is entirely gated by Symfony's own profiler access control. Adding a second layer here would be inconsistent with every other panel section. |
| 9 | External duties | settled | Inherits axis 6's decision (message shown). Owner accepts the residual risk; no additional filtering/redaction duty added. |
| 5 | Dependencies | settled | No new dependency — reuses `CostSnapshot`, `CostTracker`, `LlmCostCollector`, the twig template, and the already-optional `$this->logger` (shipped v0.5.0, `767588b`). |
| 8 | Operations | settled | Owner decision (round 1): cap the list (first 10 + "and N more" line), diverging deliberately from the uncapped per-call detail table — a full-outage panel should not become unreadable. |
| 4 | Lifecycle | settled | Begins when `compute()` runs, ends at `CostTracker::reset()` (inherited from axis 7). No new lifecycle needed. |
| 10 | Blast radius | derivable | Purely additive, read-only display data — nothing existing changes behaviour. `CostSnapshot`'s constructor gains a field, which touches every direct instantiation site; per this session's knowledge `CostSnapshot` is constructed only once, in `CostTracker::compute()`'s final `return` — worth a fresh grep at implementation time to confirm exhaustively (not re-verified in this probe). |
| 11 | Abandonment | derivable | The scenario from Stage 1 Q2 (process dies before `lateCollect()`) is already covered: the Monolog warning fires synchronously **inside** the catch block, independent of `lateCollect()` — so the log (when enabled) survives abnormal termination even though the profiler notice is best-effort. Decision to confirm: is "profiler = best-effort, log = safety net" the accepted framing? |
| 12 | Incentive | n/a | Diagnostic tooling in a dev-only debug panel, not a feature end-users choose to re-engage with — no "coming back" mechanic to decide, consistent with the rest of the bundle. |
| 13 | Success/removal | **open (owner-deferred)** | Owner decision (round 1): leave open — small, low-risk diagnostic feature, no fixed success metric or review date required to ship. Documented gap, not a failure. |

**Residual line:** not examined — `CostSnapshot`'s exhaustive constructor call-site list (axis 10) was reasoned from session memory, not freshly grepped; no domain research was needed or performed (the axes were answerable entirely from local material).
