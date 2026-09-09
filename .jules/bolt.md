## YYYY-MM-DD - [Optimize DashboardStatsController]
**Learning:** Doing multiple DB calls with Eloquent `count()` inside a controller creates severe performance issues and N+1 like patterns by repeating multiple SQL aggregations separately. They can easily be reduced by using `.toBase()` to get the raw builder coupled with `selectRaw` utilizing `count(*)` and conditionals `sum(case when condition then 1 else 0 end)`.
**Action:** When seeing multiple counts or conditional aggregates on the same table, merge them into a single raw DB query utilizing case expressions.
