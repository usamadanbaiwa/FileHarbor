# Releases

One version tag creates one GitHub Release with two downloads:

```text
v1.0.0
  fileharbor-php-1.0.0.zip
  fileharbor-node-1.0.0.tar.gz
```

After merging changes, push a tag on the commit you want to release:

```sh
git tag -a v1.0.0 -m "FileHarbor 1.0.0"
git push origin v1.0.0
```

The **Release archives** workflow tests both applications, builds their packages, and publishes the release with generated notes. If either application's checks fail, publication does not run. The tag determines the archive version; the workflow updates package metadata in its temporary checkout without committing it back.

Use a new tag for each release, such as `v1.0.1`. This workflow accepts stable versions in the form `vMAJOR.MINOR.PATCH`.

To try the builds without publishing, open **Actions → Release archives → Run workflow** and enter a version without the `v`, such as `1.0.0`. Download the packages from the run's artifacts.

The workflow uses GitHub's automatic token; no personal access token is needed. Repository or organization policies must allow Actions to run and grant the publishing job `contents: write`.

GitHub also adds automatic source downloads containing the whole repository. Users should choose the named PHP or Node.js package for a standalone installation.
