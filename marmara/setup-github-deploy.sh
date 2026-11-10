#!/usr/bin/env bash
# One-time Google Cloud setup so GitHub Actions (Marmara-Yapay-Zeka-Kulubu/trypost) can build into
# Artifact Registry and deploy to the VMs, without any stored key.
# Run once in Cloud Shell as a project owner: bash marmara/setup-github-deploy.sh
set -euo pipefail

PROJECT_ID=mayk-web-2026
REPO=Marmara-Yapay-Zeka-Kulubu/trypost
SA_NAME=github-deployer
POOL=github
PROVIDER=marmara-trypost
AR_LOCATION=europe-west1
AR_REPO=mayk

gcloud config set project "$PROJECT_ID" >/dev/null
PROJECT_NUMBER=$(gcloud projects describe "$PROJECT_ID" --format='value(projectNumber)')
SA="$SA_NAME@$PROJECT_ID.iam.gserviceaccount.com"

gcloud services enable iamcredentials.googleapis.com sts.googleapis.com iap.googleapis.com --quiet

gcloud iam service-accounts describe "$SA" >/dev/null 2>&1 \
  || gcloud iam service-accounts create "$SA_NAME" --display-name="GitHub deploy (Marmara-Yapay-Zeka-Kulubu/trypost)"

gcloud iam workload-identity-pools describe "$POOL" --location=global >/dev/null 2>&1 \
  || gcloud iam workload-identity-pools create "$POOL" --location=global --display-name="GitHub Actions"

gcloud iam workload-identity-pools providers describe "$PROVIDER" --location=global --workload-identity-pool="$POOL" >/dev/null 2>&1 \
  || gcloud iam workload-identity-pools providers create-oidc "$PROVIDER" \
       --location=global --workload-identity-pool="$POOL" \
       --issuer-uri="https://token.actions.githubusercontent.com" \
       --attribute-mapping="google.subject=assertion.sub,attribute.repository=assertion.repository,attribute.ref=assertion.ref" \
       --attribute-condition="assertion.repository=='$REPO'"

# Only workflows of this one repository may act as the deploy account.
gcloud iam service-accounts add-iam-policy-binding "$SA" --quiet \
  --role=roles/iam.workloadIdentityUser \
  --member="principalSet://iam.googleapis.com/projects/$PROJECT_NUMBER/locations/global/workloadIdentityPools/$POOL/attribute.repository/$REPO" >/dev/null

# Push images to the one Artifact Registry repository.
gcloud artifacts repositories add-iam-policy-binding "$AR_REPO" --location="$AR_LOCATION" --quiet \
  --member="serviceAccount:$SA" --role=roles/artifactregistry.writer >/dev/null

# SSH to the VMs through IAP (gcloud compute ssh --tunnel-through-iap).
for ROLE in roles/iap.tunnelResourceAccessor roles/compute.instanceAdmin.v1 roles/compute.osAdminLogin; do
  gcloud projects add-iam-policy-binding "$PROJECT_ID" --quiet --condition=None \
    --member="serviceAccount:$SA" --role="$ROLE" >/dev/null
done

# gcloud compute ssh may need to act as the VMs' service account.
for VM in mayk-devstage mayk-vm; do
  VM_SA=$(gcloud compute instances describe "$VM" --zone=europe-west1-b --format='value(serviceAccounts[0].email)')
  [ -n "$VM_SA" ] && gcloud iam service-accounts add-iam-policy-binding "$VM_SA" --quiet \
    --member="serviceAccount:$SA" --role=roles/iam.serviceAccountUser >/dev/null
done

REVERB_APP_KEY=$(gcloud compute ssh mayk-devstage --zone europe-west1-b --tunnel-through-iap --quiet \
  --command "sudo grep -m1 '^REVERB_APP_KEY=' /opt/trypost-marmara/.env | cut -d= -f2-" 2>/dev/null | tr -d '\r"')

echo
echo "Add these as repository variables in GitHub (Settings > Secrets and variables > Actions > Variables):"
echo "GCP_WORKLOAD_IDENTITY_PROVIDER=projects/$PROJECT_NUMBER/locations/global/workloadIdentityPools/$POOL/providers/$PROVIDER"
echo "GCP_SERVICE_ACCOUNT=$SA"
echo "REVERB_APP_KEY=$REVERB_APP_KEY"
