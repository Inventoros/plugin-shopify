# FAQ

## FAQ

**Does Inventoros slow down when Shopify is slow or down?** No. Requests only queue work; Shopify outages show up as retries and failed items, and the connection pauses itself after repeated failures.

**What if the same order arrives twice, or the update before the create?** It is imported once. Older updates are ignored.

**Can two stores sync into one organization?** Yes, one connection per store; each has its own mappings. Two organizations can also connect two different stores; they never see each other's data.

**What happens to my data if I remove the plugin?** Its own tables are dropped. Imported orders, stock adjustments and products stay in Inventoros.

**Where does the integration kit live?** In `src/Kit`, with a README on copying it into another integration plugin.
