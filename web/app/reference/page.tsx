'use client';

import { ApiReferenceReact } from '@scalar/api-reference-react';

export default function ReferencePage() {
  return (
    <div className="-mx-6 -mb-20">
      <ApiReferenceReact
        configuration={{
          spec: { url: '/openapi.yaml' },
          theme: 'default',
          hideDarkModeToggle: false,
          hideClientButton: false,
        }}
      />
    </div>
  );
}
