# Aike platform

This repository hosts the **Aike** logistics platform:

- **`logistics-app/`** — the existing web application and its PHP backend (single source of truth
  for auth, business rules, payments, maps, notifications). Unchanged by the mobile work.
- **`mobile/`** — the Android & iOS app (React Native + Expo). A thin client over the same
  backend via a versioned JSON API. See [`mobile/README.md`](mobile/README.md) and
  [`mobile/docs/`](mobile/docs) (audit, feature-parity matrix, architecture, API specs, roadmap).
- **`shared/`** — non-sensitive contracts shared by web/mobile (status, vehicle, role identifiers,
  API types). No business logic, no secrets.

The web and mobile apps operate independently on the **same** trusted backend and database.

---

_Original template README (repository scaffold) below._

This is repository with examples of simple UI components. The repository is based on Next.js and React.js. 

# installation

* Clone the repo with
```
git clone git@github.com:atherosai/ui.git
```

# For HTML/CSS/JS

Just navigate to the folder with your chosen example and open html file in the browser.

# For React Examples

* Install npm packages
```
npm i 
```
* Run development mode
```
npm run dev
```

Now the app is accessible at ```localhost:3000```


# My Social Media
The examples are posted here:

* [TikTok](https://www.tiktok.com/@davidm_ai)
* [Instagram](https://www.instagram.com/davidm_ai/)
* [Youtube](https://www.youtube.com/@Atheroslearning)
* [Twitter](https://twitter.com/davidm_ml)
* [Linkedin](https://twitter.com/davidm_ml)
