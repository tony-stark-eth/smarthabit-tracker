# Statistics & Analytics
## Statistics & Analytics

Statistics come as a standalone Phase 5, after the learning system (Phase 4) provides data.

### API Endpoints

```
GET /api/v1/habits/{id}/stats          — Stats per habit
GET /api/v1/stats/household            — Household-wide overview
GET /api/v1/stats/user/{id}            — Stats per user (optional)
```

### Stats per Habit

- **Streak**: Current and longest series of consecutive days (timezone-aware, based on user local time)
- **Completion Rate**: Percentage of days in the last month / quarter / year on which the habit was completed — relative to `frequency`/`frequency_days`
- **Average Time**: Median of log times (same logic as TimeWindowLearner)
- **Trend**: Is the habit being completed earlier/later than 30 days ago? Is the completion rate rising/falling?
- **Distribution by User**: Who completes the habit how often? (Percentage per household member)

### Household Dashboard

- **Overall Completion Rate**: All habits aggregated
- **Most Active User**: Who logs the most?
- **Habit Ranking**: Which habits are completed most reliably, which are often forgotten?
- **Weekday Heatmap**: On which days are habits most often forgotten?
- **Time Heatmap**: At what times of day is the most logging happening? (Aggregated across all habits)

### Technical Implementation

Basic stats (streak, completion rate) are calculated on-the-fly from `HabitLog` — with a few hundred logs per habit, this is performant enough as a PostgreSQL query with window functions.

For the analytics dashboard (heatmaps, trends, distributions), a **materialized view** or a separate `HabitStats` table that is populated nightly by the `app:compute-stats` command is worthwhile. This avoids expensive aggregations on every dashboard load.

Frontend: Charts via a lightweight Svelte chart library (e.g. `layerchart` or `pancake`). Heatmaps as SVG grid, no heavy chart framework needed.
