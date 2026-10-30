import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import App from './App';

describe('FARTMAR IPS Frontend SPA', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('renderiza la pantalla de login y los selectores rápidos de roles sintéticos', () => {
    render(<App />);
    expect(screen.getByText(/FARTMAR IPS — Sistema de Farmacia e Inventario/i)).toBeTruthy();
    expect(screen.getByText(/Auditor \(RN-10 Enmascarado\)/i)).toBeTruthy();
    expect(screen.getByText(/Regente 2 \(Andrés - 2da Firma\/Aprobador\)/i)).toBeTruthy();
  });

  it('autocompleta el correo del rol seleccionado al hacer clic en un chip de prueba', () => {
    render(<App />);
    const auditorChip = screen.getByText(/Auditor \(RN-10 Enmascarado\)/i);
    fireEvent.click(auditorChip);

    const emailInput = screen.getByDisplayValue('auditor@fartmar.test');
    expect(emailInput).toBeTruthy();
  });

  it('muestra las 4 pestañas operativas (Inventario, Dispensación FEFO, Traslados e IA) cuando hay sesión activa', () => {
    localStorage.setItem('fartmar_token', 'fake-sanctum-token');
    localStorage.setItem(
      'fartmar_user',
      JSON.stringify({
        id: 2,
        name: 'Laura Regente Principal',
        email: 'regente@fartmar.test',
        role: 'regente_farmacia',
      })
    );

    render(<App />);
    expect(screen.getByText(/1\. Inventario, Alertas \(RN-11\) y Kardex \(RN-06\)/i)).toBeTruthy();
    expect(screen.getByText(/2\. Dispensación FEFO \(RN-01 a RN-05, RN-09\)/i)).toBeTruthy();
    expect(screen.getByText(/3\. Traslados entre Bodegas \(RN-07 y RN-08\)/i)).toBeTruthy();
    expect(screen.getByText(/4\. Asistente IA \(Parte C\) y Auditoría \(RN-10\)/i)).toBeTruthy();
  });
});